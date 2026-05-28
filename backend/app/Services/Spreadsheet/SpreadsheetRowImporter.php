<?php

namespace App\Services\Spreadsheet;

use App\Models\Service;
use App\Models\ServiceItem;
use App\Services\ServiceCodeGenerator;
use App\Models\User;
use Carbon\Carbon;

final class SpreadsheetRowImporter
{
    public function __construct(private ServiceCodeGenerator $codes) {}

    /**
     * @param  array{company_id: int, user_id: int, kind: string, inventory_lot_id: ?int,
     *               service_type: string, description: string, client_name: string,
     *               amount: string, service_date: string, status: string}  $payload
     */
    public function create(array $payload, User $actor): void
    {
        $date = Carbon::parse($payload['service_date'], config('app.timezone'))->startOfDay();
        $code = $this->codes->nextForDate($date, ServiceCodeGenerator::KIND_SERVICIO);

        $service = Service::query()->create([
            'code' => $code,
            'company_id' => $payload['company_id'],
            'inventory_lot_id' => $payload['inventory_lot_id'],
            'user_id' => $payload['user_id'],
            'kind' => $payload['kind'],
            'client_name' => $payload['client_name'],
            'service_type' => $payload['service_type'],
            'description' => $payload['description'],
            'amount' => $payload['amount'],
            'service_date' => $payload['service_date'],
            'status' => $payload['status'],
        ]);

        $this->syncSingleImportLine($service, $payload);
    }

    /**
     * @param  array{company_id: int, user_id: int, kind: string, inventory_lot_id: ?int,
     *               service_type: string, description: string, client_name: string,
     *               amount: string, service_date: string, status: string}  $payload
     */
    public function update(Service $service, array $payload): void
    {
        if ($service->status === Service::STATUS_ELIMINADO && $payload['status'] !== Service::STATUS_ELIMINADO) {
            throw new \InvalidArgumentException('No se puede reactivar un servicio eliminado desde importación.');
        }

        $service->fill([
            'company_id' => $payload['company_id'],
            'inventory_lot_id' => $payload['inventory_lot_id'],
            'user_id' => $payload['user_id'],
            'kind' => $payload['kind'],
            'client_name' => $payload['client_name'],
            'service_type' => $payload['service_type'],
            'description' => $payload['description'],
            'amount' => $payload['amount'],
            'service_date' => $payload['service_date'],
            'status' => $payload['status'],
        ]);
        $service->save();

        $this->syncSingleImportLine($service, $payload);
    }

    /** @param array{service_type: string, description: string, amount: string} $payload */
    private function syncSingleImportLine(Service $service, array $payload): void
    {
        ServiceItem::query()->where('service_id', $service->id)->delete();
        ServiceItem::query()->create([
            'service_id' => $service->id,
            'catalog_id' => null,
            'catalog_suggestion_id' => null,
            'label' => $payload['service_type'],
            'line_description' => $payload['description'],
            'amount' => $payload['amount'],
            'technician_line_amount' => $payload['amount'],
            'sort_order' => 0,
        ]);
    }
}
