<?php

namespace App\Console\Commands;

use App\Services\CoreMarketBranchInventoryService;
use Illuminate\Console\Command;

class CoreMarketBranchInventoryInitialize extends Command
{
    protected $signature = 'coremarket:branch-inventory-initialize
        {--dry-run : Preview initialization without writing}
        {--apply : Create missing default-branch balances}
        {--confirm-branch-inventory : Required confirmation for --apply}
        {--branch-id= : Target branch, defaults to the default branch}';

    protected $description = 'Initialize branch balances from current aggregate product stock';

    public function handle(CoreMarketBranchInventoryService $inventory): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->option('confirm-branch-inventory')) {
            $this->error('--apply requires --confirm-branch-inventory.');

            return self::FAILURE;
        }

        $branchId = $this->option('branch-id');
        $result = $inventory->initializeDefaultBranchBalances(
            $apply,
            $branchId === null || $branchId === '' ? null : (int) $branchId
        );

        $this->info($apply ? 'Branch inventory initialization applied.' : 'Dry run only; no rows were changed.');
        $this->table(
            ['Branch', 'Scanned', 'Would create/created', 'Skipped', 'Differences'],
            [[
                "{$result['branch_name']} (#{$result['branch_id']})",
                $result['scanned'],
                $result['created'],
                $result['skipped'],
                $result['differences'],
            ]]
        );

        return self::SUCCESS;
    }
}
