<?php

namespace App\Services;

use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\Organization;
use App\Models\SupplierAccount;
use App\Support\ApiProblem;
use Illuminate\Support\Str;

final class ImportAccess
{
    public function account(Organization $organization, string $id, ?string $location = null): SupplierAccount
    {
        if (! Str::isUuid($id) || ($location !== null && ! Str::isUuid($location))) {
            $this->missing();
        }
        $query = $organization->supplierAccounts()->whereKey($id);
        if ($location !== null) {
            $query->where('restaurant_location_id', $location);
        }
        $account = $query->first();
        if (! $account instanceof SupplierAccount || ! $organization->restaurantLocations()->whereKey($account->restaurant_location_id)->exists()) {
            $this->missing();
        }

        return $account;
    }

    public function run(Organization $organization, string $id, bool $lock = false): ImportRun
    {
        if (! Str::isUuid($id)) {
            $this->missing();
        }
        $query = ImportRun::query()->where('organization_id', $organization->id)->whereKey($id);
        $run = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $run instanceof ImportRun) {
            $this->missing();
        }
        $this->account($organization, $run->supplier_account_id, $run->restaurant_location_id);

        return $run;
    }

    public function file(ImportRun $run, ?string $id = null): ImportFile
    {
        if ($id !== null && ! Str::isUuid($id)) {
            $this->missing();
        }
        $query = ImportFile::query()->where('organization_id', $run->organization_id)->where('import_run_id', $run->id);
        $file = ($id === null ? $query : $query->whereKey($id))->first();
        if (! $file instanceof ImportFile) {
            $this->missing();
        }

        return $file;
    }

    private function missing(): never
    {
        throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
    }
}
