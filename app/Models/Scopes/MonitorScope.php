<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class MonitorScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $loggedin_user_type = auth()->user()->user_type;
        $user = auth()->user();
        $region_id = $user->region_id;
        $branch_id = $user->branch_id;

        //use a switch statement to check the user type and sum the outstanding interest
        switch ($loggedin_user_type) {
            case 5:
            case 4:
                break;
            case 3:
                $builder->join('officers', 'officers.staff_id', '=', 'monitors.staff_id')
                    ->where('officers.region_id', $user->region_id);
                break;
            case 2:
                $builder->join('officers', 'officers.staff_id', '=', 'monitors.staff_id')
                    ->where('officers.branch_id', $user->branch_id);
                break;
            case 1:
                $builder->where('staff_id', $user->staff_id);
                break;
        }
    }
}
