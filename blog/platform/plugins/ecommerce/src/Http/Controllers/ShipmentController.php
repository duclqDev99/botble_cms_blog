<?php

namespace Botble\Ecommerce\Http\Controllers;

use Botble\Base\Facades\Assets;
use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Supports\Breadcrumb;
use Botble\Ecommerce\Enums\ShippingCodStatusEnum;
use Botble\Ecommerce\Enums\ShippingStatusEnum;
use Botble\Ecommerce\Events\ShippingStatusChanged;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Http\Requests\UpdateShipmentCodStatusRequest;
use Botble\Ecommerce\Http\Requests\UpdateShipmentStatusRequest;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Models\Shipment;
use Botble\Ecommerce\Models\ShipmentHistory;
use Botble\Ecommerce\Tables\ShipmentTable;
use Botble\GHTK\GHTK;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class ShipmentController extends BaseController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('plugins/ecommerce::shipping.shipments'), route('ecommerce.shipments.index'));
    }

    public function index(ShipmentTable $dataTable)
    {
        $this->pageTitle(trans('plugins/ecommerce::shipping.shipments'));

        return $dataTable->renderTable();
    }

    public function edit(int|string $id)
    {
        Assets::addStylesDirectly('vendor/core/plugins/ecommerce/css/ecommerce.css')
            ->addScriptsDirectly('vendor/core/plugins/ecommerce/js/shipment.js');

        $shipment = Shipment::query()->findOrFail($id);

        $this->pageTitle(trans('plugins/ecommerce::shipping.edit_shipping', ['code' => get_shipment_code($id)]));

        return view('plugins/ecommerce::shipments.edit', compact('shipment'));
    }

    public function postUpdateStatus(Shipment $shipment, UpdateShipmentStatusRequest $request)
    {
        $previousShipment = $shipment->toArray();
        $shipment->status = $request->input('status');
        $shipment->save();

        ShipmentHistory::query()->create([
            'action' => 'update_status',
            'description' => trans('plugins/ecommerce::shipping.changed_shipping_status', [
                'status' => $shipment->status->label(),
            ]),
            'shipment_id' => $shipment->getKey(),
            'order_id' => $shipment->order_id,
            'user_id' => Auth::id() ?? 0,
        ]);

        OrderHistory::query()->create([
            'action' => 'update_shipping_status',
            'description' => trans('plugins/ecommerce::shipping.changed_shipping_status', [
                'status' => $shipment->status->label(),
            ]),
            'order_id' => $shipment->order_id,
            'user_id' => Auth::id() ?? 0,
        ]);

        switch ($shipment->status) {
            case ShippingStatusEnum::DELIVERED:
                $shipment->date_shipped = Carbon::now();
                $shipment->save();

                OrderHelper::shippingStatusDelivered($shipment, $request, Auth::id() ?? 0);

                break;

            case ShippingStatusEnum::CANCELED:
                OrderHistory::query()->create([
                    'action' => 'cancel_shipment',
                    'description' => trans('plugins/ecommerce::shipping.shipping_canceled_by'),
                    'order_id' => $shipment->order_id,
                    'user_id' => Auth::id(),
                ]);

                break;
        }

        event(new ShippingStatusChanged($shipment, $previousShipment));

        return $this
            ->httpResponse()
            ->setMessage(trans('plugins/ecommerce::shipping.update_shipping_status_success'));
    }

    public function postUpdateCodStatus(Shipment $shipment, UpdateShipmentCodStatusRequest $request)
    {
        $shipment->cod_status = $request->input('status');
        $shipment->save();

        if ($shipment->cod_status == ShippingCodStatusEnum::COMPLETED) {
            OrderHelper::confirmPayment($shipment->order);
        }

        ShipmentHistory::query()->create([
            'action' => 'update_cod_status',
            'description' => trans('plugins/ecommerce::shipping.updated_cod_status_by', [
                'status' => $shipment->cod_status->label(),
            ]),
            'shipment_id' => $shipment->getKey(),
            'order_id' => $shipment->order_id,
            'user_id' => Auth::id() ?? 0,
        ]);

        OrderHistory::query()->create([
            'action' => 'update_cod_status',
            'description' => trans('plugins/ecommerce::shipping.updated_cod_status_by', [
                'status' => $shipment->cod_status->label(),
            ]),
            'order_id' => $shipment->order_id,
            'user_id' => Auth::id() ?? 0,
        ]);

        return $this
            ->httpResponse()
            ->setMessage(trans('plugins/ecommerce::shipping.update_cod_status_success'));
    }

    public function update(Shipment $shipment, Request $request)
    {
        $shipment->fill(
            $request->only([
                'tracking_id',
                'shipping_company_name',
                'tracking_link',
                'estimate_date_shipped',
                'note',
            ])
        );

        $shipment->save();

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('ecommerce.shipments.index'))
            ->withUpdatedSuccessMessage();
    }

    public function print(Request $request)
    {
        $result = app(GHTK::class)->print($request->shipment_id);
        if (!$result) return response()->json(['message' => "In thất bại"], 500);

        return Response::make($result, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="nhan_don_hang.pdf"'
        ]);
    }

    public function destroy(Shipment $shipment)
    {
        return DeleteResourceAction::make($shipment);
    }
}
