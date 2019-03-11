<?php

namespace App\Http\Controllers\Api\HumanResource\Employee;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\HumanResource\Kpi\KpiAutomatedController;

use App\Model\HumanResource\Kpi\Kpi;
use App\Model\HumanResource\Kpi\KpiGroup;
use App\Model\HumanResource\Kpi\KpiIndicator;
use App\Model\HumanResource\Kpi\KpiScore;
use App\Http\Resources\HumanResource\Kpi\KpiCategory\KpiResource;
use App\Http\Resources\HumanResource\Kpi\KpiCategory\KpiCollection;

class EmployeeAssessmentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param $employeeId
     *
     * @return \App\Http\Resources\HumanResource\Kpi\KpiCategory\KpiCollection
     */
    public function index($employeeId)
    {
        $type = request()->get('type');

        $kpis = Kpi::join('kpi_groups', 'kpi_groups.kpi_id', '=', 'kpis.id')
            ->join('kpi_indicators', 'kpi_groups.id', '=', 'kpi_indicators.kpi_group_id')
            ->select('kpis.*')
            ->addSelect(DB::raw('sum(kpi_indicators.weight) / count(DISTINCT kpis.id) as weight'))
            ->addSelect(DB::raw('sum(kpi_indicators.target) / count(DISTINCT kpis.id) as target'))
            ->addSelect(DB::raw('sum(kpi_indicators.score) / count(DISTINCT kpis.id) as score'))
            ->addSelect(DB::raw('sum(kpi_indicators.score_percentage) / count(DISTINCT kpis.id) as score_percentage'))
            ->addSelect(DB::raw('count(DISTINCT kpis.id) as num_of_scorer'));

        if ($type === 'all') $kpis = $kpis->groupBy('kpis.id');
        if ($type === 'daily') $kpis = $kpis->groupBy('kpis.date');
        if ($type === 'weekly') $kpis = $kpis->groupBy(DB::raw('yearweek(kpis.date)'));
        if ($type === 'monthly') $kpis = $kpis->groupBy(DB::raw('year(kpis.date)'), DB::raw('month(kpis.date)'));
        if ($type === 'yearly') $kpis = $kpis->groupBy(DB::raw('year(kpis.date)'));

        $kpis = $kpis->where('employee_id', $employeeId)->orderBy('kpis.date', 'asc')->get();

        $dates = [];
        $scores = [];

        $kpi_automated_controller = new KpiAutomatedController();

        foreach ($kpis as $key => $kpi) {
            array_push($dates, date('dMY', strtotime($kpi->date)));
            array_push($scores, number_format($kpi->indicators->sum('score_percentage'), 2));

            foreach ($kpi->groups as $key => $group) {
                foreach ($group->indicators as $key => $indicator) {
                    if ($indicator->automated_id) {
                        $data = $kpi_automated_controller->getAutomatedData($indicator->automated_id, $kpi->date);

                        $indicator->target = $data['target'];
                        $indicator->score = $data['score'];
                        $indicator->score_percentage = $indicator->score / $indicator->target * $indicator->weight;

                        $group->target += $indicator->target;
                        $group->score += $indicator->score;
                        $group->score_percentage += $indicator->score_percentage;

                        $kpi->target += $indicator->target;
                        $kpi->score += $indicator->score;
                        $kpi->score_percentage += $indicator->score_percentage;                 
                    }
                }
            }
        }

        return (new KpiCollection($kpis))
            ->additional([
                'data_set' => [
                    'dates' => $dates,
                    'scores' => $scores,
                ],
            ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param                           $employeeId
     *
     * @return void
     */
    public function store(Request $request, $employeeId)
    {
        $template = $request->get('template');

        DB::connection('tenant')->beginTransaction();

        $kpi = new Kpi;
        $kpi->name = $template['name'];
        $kpi->date = date('Y-m-d', strtotime($request->get('date')));
        $kpi->employee_id = $employeeId;
        $kpi->scorer_id = auth()->user()->id;
        $kpi->save();

        for ($groupIndex = 0; $groupIndex < count($template['groups']); $groupIndex++) {
            $kpiGroup = new KpiGroup;
            $kpiGroup->kpi_id = $kpi->id;
            $kpiGroup->name = $template['groups'][$groupIndex]['name'];
            $kpiGroup->save();

            for ($indicatorIndex = 0; $indicatorIndex < count($template['groups'][$groupIndex]['indicators']); $indicatorIndex++) {
                $kpiIndicator = new KpiIndicator;
                $kpiIndicator->kpi_group_id = $kpiGroup->id;
                $kpiIndicator->name = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['name'];
                $kpiIndicator->weight = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['weight'];

                if ($template['groups'][$groupIndex]['indicators'][$indicatorIndex]['automated_id']) {
                    $kpiIndicator->automated_id = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['automated_id'];
                    $kpiIndicator->target = 0;
                    $kpiIndicator->score = 0;
                    $kpiIndicator->score_percentage = 0;
                    $kpiIndicator->score_description = '';
                }
                else {
                    $kpiIndicator->target = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['target'];
                    $kpiIndicator->score = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['selected']['score'];
                    $kpiIndicator->score_percentage = $kpiIndicator->weight * $kpiIndicator->score / $kpiIndicator->target;
                    $kpiIndicator->score_description = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['selected']['description'];
                }

                $kpiIndicator->save();

                if (!$template['groups'][$groupIndex]['indicators'][$indicatorIndex]['automated_id']) {
                    for ($scoreIndex = 0; $scoreIndex < count($template['groups'][$groupIndex]['indicators'][$indicatorIndex]['scores']); $scoreIndex++) {
                        $kpiScore = new KpiScore();
                        $kpiScore->kpi_indicator_id = $kpiIndicator->id;
                        $kpiScore->description = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['scores'][$scoreIndex]['description'];
                        $kpiScore->score = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['scores'][$scoreIndex]['score'];
                        $kpiScore->save();
                    }
                }
            }
        }

        DB::connection('tenant')->commit();
    }

    /**
     * Display the specified resource.
     *
     * @param  int $employee_id
     * @param  int $id
     *
     * @return \App\Http\Resources\HumanResource\Kpi\KpiCategory\KpiResource
     */
    public function show($employeeId, $id)
    {
        $kpis = Kpi::join('kpi_groups', 'kpi_groups.kpi_id', '=', 'kpis.id')
            ->join('kpi_indicators', 'kpi_groups.id', '=', 'kpi_indicators.kpi_group_id')
            ->select('kpis.*')
            ->addSelect(DB::raw('sum(kpi_indicators.weight) / count(DISTINCT kpis.id) as weight'))
            ->addSelect(DB::raw('sum(kpi_indicators.target) / count(DISTINCT kpis.id) as target'))
            ->addSelect(DB::raw('sum(kpi_indicators.score) / count(DISTINCT kpis.id) as score'))
            ->addSelect(DB::raw('sum(kpi_indicators.score_percentage) / count(DISTINCT kpis.id) as score_percentage'))
            ->addSelect(DB::raw('count(DISTINCT kpis.id) as num_of_scorer'))
            ->where('employee_id', $employeeId)
            ->where('kpis.id', $id)
            ->first();

        $kpis->score = (double)$kpis->score;
        $kpis->target = (double)$kpis->target;

        return new KpiResource($kpis);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $employeeId, $id)
    {
        $template = $request->get('template');

        DB::connection('tenant')->beginTransaction();

        $kpi = Kpi::findOrFail($id);
        $kpi->date = date('Y-m-d', strtotime($request->get('date')));
        $kpi->save();

        for ($groupIndex = 0; $groupIndex < count($template['groups']); $groupIndex++) {
            $kpiGroup = KpiGroup::findOrFail($template['groups'][$groupIndex]['id']);

            for ($indicatorIndex = 0; $indicatorIndex < count($template['groups'][$groupIndex]['indicators']); $indicatorIndex++) {
                $kpiIndicator = KpiIndicator::findOrFail($template['groups'][$groupIndex]['indicators'][$indicatorIndex]['id']);

                if (!$kpiIndicator->automated_id) {
                    $kpiIndicator->kpi_group_id = $kpiGroup->id;
                    $kpiIndicator->name = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['name'];
                    $kpiIndicator->weight = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['weight'];
                    $kpiIndicator->target = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['target'];
                    $kpiIndicator->score = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['selected']['score'];
                    $kpiIndicator->score_percentage = $kpiIndicator->weight * $kpiIndicator->score / $kpiIndicator->target;
                    $kpiIndicator->score_description = $template['groups'][$groupIndex]['indicators'][$indicatorIndex]['selected']['description'];
                    $kpiIndicator->save();
                }
            }
        }

        DB::connection('tenant')->commit();
        
        return new KpiResource($kpi);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     *
     * @return \App\Http\Resources\HumanResource\Kpi\KpiCategory\KpiResource
     */
    public function destroy($employeeId, $id)
    {
        $kpi = Kpi::findOrFail($id);

        if ($kpi->scorer_id != auth()->user()->id) {
            return response()->json(['message' => 'Unauthorized access'], 401);
        }

        $kpi->delete();

        return new KpiResource($kpi);
    }
}
