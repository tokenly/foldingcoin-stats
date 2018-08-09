<?php

namespace App\Libraries\StatsDownload;

use App\Libraries\StatsDownload\StatsDownloadAPIClient;
use App\Models\FoldingStat;
use App\Repositories\FoldingMemberRepository;
use App\Repositories\FoldingStatRepository;
use App\Repositories\FoldingTeamRepository;
use App\Repositories\MemberAggregateStatRepository;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tokenly\LaravelEventLog\Facade\EventLog;

/**
 * MembersSynchronizer
 */

class MembersSynchronizer
{

    public function __construct(StatsDownloadAPIClient $stats_api_client, FoldingMemberRepository $folding_member_repository, FoldingStatRepository $folding_stat_repository, FoldingTeamRepository $folding_team_repository, MemberAggregateStatRepository $member_aggregate_stat_repository)
    {
        $this->stats_api_client = $stats_api_client;
        $this->folding_member_repository = $folding_member_repository;
        $this->folding_stat_repository = $folding_stat_repository;
        $this->folding_team_repository = $folding_team_repository;
        $this->member_aggregate_stat_repository = $member_aggregate_stat_repository;
    }

    public function synchronizeDateRange(Carbon $start_date, Carbon $end_date)
    {
        $working_date = $start_date->copy();
        $working_date->startOfHour();

        // do all daily synchronizations
        while ($working_date->lt($end_date)) {
            $period_type = FoldingStat::PERIOD_DAILY;

            // Log::debug("Synching PERIOD_DAILY for $working_date");
            $this->synchronizeMemberStats($working_date, $period_type);

            // increment
            $working_date->addDay(1);
        }

        // do hourly synch (if needed)
        $working_date = $this->oldestHourGranularityDate();
        while ($working_date->lt($end_date)) {
            if ($working_date->gte($start_date)) {
                $period_type = FoldingStat::PERIOD_HOURLY;

                // Log::debug("Synching PERIOD_HOURLY for $working_date");
                $this->synchronizeMemberStats($working_date, $period_type);
            }

            // increment
            $working_date->addHour(1);
        }

        // always sync aggregate stats after updating member stats records
        $this->member_aggregate_stat_repository->aggregateStats();
    }

    // public for testing only - use synchronizeDateRange
    public function synchronizeMemberStats(Carbon $start_date, $period_type)
    {
        $start_date = $start_date->copy()->startOfHour();

        switch ($period_type) {
            case FoldingStat::PERIOD_HOURLY:
                $end_date = $start_date->copy()->addHour(1)->subMinute(1);
                break;
            case FoldingStat::PERIOD_DAILY:
                $end_date = $start_date->copy()->addDay(1)->subMinute(1);
                break;
            case FoldingStat::PERIOD_MONTHLY:
                $end_date = $start_date->copy()->addMonth(1)->subMinute(1);
                break;
            default:
                throw new Exception("Unknown period type", 1);
        }

        // echo "start: $start_date\n";
        // echo "end: $end_date\n";
        $existing_members_by_key = $this->folding_member_repository->allMemberAttributesByUniqueKey();
        $new_members_by_key = collect($this->stats_api_client->getMemberStats($start_date, $end_date)['members'])->keyBy(function ($m) {
            return $m['userName'] . '|' . $m['teamNumber'];
        });

        // member records
        $this->synchronizeMemberRecords($existing_members_by_key, $new_members_by_key);

        // member record stats
        $existing_member_ids_by_key = $this->folding_member_repository->allMemberIdsByUniqueKey();
        $this->synchronizeMemberStatRecords($new_members_by_key, $existing_member_ids_by_key, $start_date, $period_type);
    }

    // recent
    public function dateUsesHourGranularity(Carbon $date, Carbon $now_date = null)
    {
        $last_day_date = $this->oldestHourGranularityDate($now_date);
        return $date->gte($last_day_date);
    }

    public function oldestHourGranularityDate(Carbon $now_date = null)
    {
        if ($now_date === null) {
            $now_date = Carbon::now();
        } else {
            $now_date = $now_date->copy();
        }

        return $now_date->startOfDay()->subDay(1);
    }

    public function purgeOldHourlyStats()
    {
        $oldest_date_to_keep = $this->oldestHourGranularityDate();
        $this->folding_stat_repository->deleteStatsBefore($oldest_date_to_keep, FoldingStat::PERIOD_HOURLY);
    }

    // ------------------------------------------------------------------------

    protected function synchronizeMemberRecords($existing_members_by_key, $new_members_by_key)
    {

        // determine create, update and delete member numbers
        $member_keys_to_create = $new_members_by_key->diffKeys($existing_members_by_key)->keys();
        $member_keys_to_update = $existing_members_by_key->intersectByKeys($new_members_by_key)->keys();
        // $existing_member_keys_to_delete = $existing_members_by_key->diffKeys($new_members_by_key)->keys();

        // ---------------
        // create

        $team_by_number_cache = [];
        foreach ($member_keys_to_create as $member_key) {
            $new_member_vars = $new_members_by_key[$member_key];

            // find or create a team
            if (!isset($team_by_number_cache[$new_member_vars['teamNumber']])) {
                $team_by_number_cache[$new_member_vars['teamNumber']] = $this->folding_team_repository->findOrCreateTeamByTeamNumber($new_member_vars['teamNumber']);
            }
            $team_id = $team_by_number_cache[$new_member_vars['teamNumber']]['id'];

            $member = $this->folding_member_repository->create([
                'user_name' => $new_member_vars['userName'],
                'friendly_name' => $new_member_vars['friendlyName'],
                'bitcoin_address' => $new_member_vars['bitcoinAddress'],
                'team_number' => $new_member_vars['teamNumber'],
                'team_id' => $team_id,
            ]);

            EventLog::debug('member.created', [
                'user_name' => $member['user_name'],
                'team_number' => $member['team_number'],
            ]);
        }

        // ---------------
        // update

        foreach ($member_keys_to_update as $member_key) {
            $new_member_vars = $new_members_by_key[$member_key];
            $member = $existing_members_by_key[$member_key];

            // echo "{$new_member_vars['userName']} ?? {$member->user_name}\n";
            if (
                (string) $new_member_vars['userName'] != (string) $member->user_name
                or (string) $new_member_vars['friendlyName'] != (string) $member->friendly_name
                or (string) $new_member_vars['bitcoinAddress'] != (string) $member->bitcoin_address
                or (string) $new_member_vars['teamNumber'] != (string) $member->team_number
            ) {
                // find or create a team
                if (!isset($team_by_number_cache[$new_member_vars['teamNumber']])) {
                    $team_by_number_cache[$new_member_vars['teamNumber']] = $this->folding_team_repository->findOrCreateTeamByTeamNumber($new_member_vars['teamNumber']);
                }
                $team_id = $team_by_number_cache[$new_member_vars['teamNumber']]['id'];

                // is different
                $update_vars = [
                    'user_name' => $new_member_vars['userName'],
                    'friendly_name' => $new_member_vars['friendlyName'],
                    'bitcoin_address' => $new_member_vars['bitcoinAddress'],
                    'team_number' => $new_member_vars['teamNumber'],
                    'team_id' => $team_id,
                ];

                $member_model = $this->folding_member_repository->findByUsernameAndTeamNumber($member->user_name, $member->team_number);
                $this->folding_member_repository->update($member_model, $update_vars);
                EventLog::debug('member.updated', [
                    'user_name' => $new_member_vars['userName'],
                    'friendly_name' => $new_member_vars['friendlyName'],
                    'bitcoin_address' => $new_member_vars['bitcoinAddress'],
                    'team_number' => $new_member_vars['teamNumber'],
                    'team_id' => $team_id,
                ]);
            }
        }

        // ---------------
        // delete

        // foreach ($existing_member_keys_to_delete as $member_key) {
        //     $member = $existing_members_by_key[$member_key];
        //     $member_model = $this->folding_member_repository->findByTeamNumber($member->number);
        //     $this->folding_member_repository->delete($member_model);
        //     EventLog::debug('member.deleted', [
        //         'number' => $member->number,
        //         'name' => $member->name,
        //     ]);
        // }
    }

    protected function synchronizeMemberStatRecords($new_members_by_key, $existing_member_ids_by_key, Carbon $start_date, $period_type)
    {

        // load existing stats for this time period
        $existing_stats_by_key = $this->folding_stat_repository->allEntryObjectsByDateAndPeriod($start_date, $period_type)->keyBy(function ($stat) {
            return $stat->member_id . '|' . $stat->start_date;
        });
        // echo "\$existing_stats_by_key for $start_date: ".json_encode($existing_stats_by_key, 192)."\n";

        // build new stats for this time period
        $start_date_string = (string) $start_date;
        $new_stats_by_key = $new_members_by_key->keyBy(function ($stat) use ($existing_member_ids_by_key, $start_date_string, $period_type) {
            $member_id = $existing_member_ids_by_key[$stat['userName'] . '|' . $stat['teamNumber']] ?? null;
            if ($member_id === null) {
                throw new Exception("Unable to find team for user name {$stat['userName']} and team number {$stat['teamNumber']}", 1);
            }
            return $member_id . '|' . $start_date_string;
        });
        // echo "\$new_stats_by_key for $start_date: ".json_encode($new_stats_by_key, 192)."\n";

        // determine create, update and delete stat numbers
        $stat_keys_to_create = $new_stats_by_key->diffKeys($existing_stats_by_key)->keys();
        $stat_keys_to_update = $existing_stats_by_key->intersectByKeys($new_stats_by_key)->keys();
        $existing_stat_keys_to_delete = $existing_stats_by_key->diffKeys($new_stats_by_key)->keys();

        // ---------------
        // create

        foreach ($stat_keys_to_create as $stat_key) {
            $new_stat_vars = $new_stats_by_key[$stat_key];

            $stat = $this->folding_stat_repository->create([
                'member_id' => $existing_member_ids_by_key[$new_stat_vars['userName'] . '|' . $new_stat_vars['teamNumber']],

                'points' => $new_stat_vars['pointsGained'],
                'work_units' => $new_stat_vars['workUnitsGained'],
                'start_date' => $start_date,
                'period_type' => $period_type,
            ]);

            EventLog::debug('stat.created', [
                'member_id' => $stat['member_id'],
                'start_date' => $stat['start_date'],
                'period_type' => $stat['period_type'],
                'points' => $stat['points'],
            ]);
        }

        // ---------------
        // update
        foreach ($stat_keys_to_update as $stat_key) {
            $new_stat_vars = $new_stats_by_key[$stat_key];
            $stat = $existing_stats_by_key[$stat_key];
            $member_id = $existing_member_ids_by_key[$new_stat_vars['userName'] . '|' . $new_stat_vars['teamNumber']] ?? null;

            // echo "{$new_stat_vars['userName']} ?? {$stat->user_name}\n";
            if (
                (string) $new_stat_vars['pointsGained'] != (string) $stat->points
                or (string) $new_stat_vars['workUnitsGained'] != (string) $stat->work_units
                or (string) $start_date != (string) $stat->start_date
                or (string) $period_type != (string) $stat->period_type
                or (string) $member_id != (string) $stat->member_id
            ) {

                // is different
                $update_vars = [
                    'member_id' => $member_id,
                    'points' => $new_stat_vars['pointsGained'],
                    'work_units' => $new_stat_vars['workUnitsGained'],
                    'start_date' => $start_date,
                    'period_type' => $period_type,
                ];

                $stat_model = $this->folding_stat_repository->findById($stat->id);
                $this->folding_stat_repository->update($stat_model, $update_vars);
                EventLog::debug('stat.updated', [
                    'member_id' => $stat->member_id,
                    'points' => $stat->points,
                    'start_date' => $stat->start_date,
                    'period_type' => $stat->period_type,
                ]);
            }
        }

        // ---------------
        // delete

        foreach ($existing_stat_keys_to_delete as $stat_key) {
            $stat = $existing_stats_by_key[$stat_key];
            $stat_model = $this->folding_stat_repository->findById($stat->id);
            $this->folding_stat_repository->delete($stat_model);
            EventLog::debug('stat.deleted', [
                'member_id' => $stat->member_id,
                'points' => $stat->points,
                'start_date' => $stat->start_date,
                'period_type' => $stat->period_type,
            ]);
        }

    }


}
