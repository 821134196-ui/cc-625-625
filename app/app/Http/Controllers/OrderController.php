<?php

namespace App\Http\Controllers;

use App\Models\MealOrder;
use App\Services\MealOrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private MealOrderService $service) {}

    public function index(Request $request)
    {
        $orders = MealOrder::with(['flight', 'task', 'activeItem'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('flight_id'), fn ($q, $f) => $q->where('flight_id', $f))
            ->orderByDesc('id')
            ->get();

        return $orders->map(fn (MealOrder $o) => $this->serialize($o));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'passenger_name' => 'required|string|max:100',
            'pnr'            => 'required|string|max:20',
            'flight_id'      => 'required|exists:flights,id',
            'seat_no'        => 'required|string|max:10',
            'meal_type'      => 'required|string',
            'notes'          => 'nullable|string|max:500',
        ]);

        $order = $this->service->createOrder($data);

        return response()->json($this->serialize($order->load(['flight', 'task', 'activeItem'])), 201);
    }

    public function show(MealOrder $order)
    {
        $order->load(['flight', 'task', 'items', 'events' => fn ($q) => $q->orderBy('id')]);

        return array_merge($this->serialize($order), [
            'items'  => $order->items->map(fn ($i) => [
                'meal_code'     => $i->meal_code,
                'flight_id'     => $i->flight_id,
                'seat_no'       => $i->seat_no,
                'label_version' => $i->label_version,
                'status'        => $i->status,
            ]),
            'events' => $order->events->map(fn ($e) => [
                'actor_role' => $e->actor_role,
                'event'      => $e->event,
                'payload'    => $e->payload,
                'at'         => $e->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function confirm(Request $request, MealOrder $order)
    {
        $data = $request->validate(['meal_type' => 'required|string']);

        return $this->serialize($this->service->confirm($order, $data['meal_type']));
    }

    public function cancel(MealOrder $order)
    {
        return $this->serialize($this->service->cancel($order));
    }

    public function rebook(Request $request, MealOrder $order)
    {
        $data = $request->validate(['flight_id' => 'required|exists:flights,id']);

        return $this->serialize($this->service->rebook($order, (int) $data['flight_id']));
    }

    public function reseat(Request $request, MealOrder $order)
    {
        $data = $request->validate(['seat_no' => 'required|string|max:10']);

        return $this->serialize($this->service->reseat($order, $data['seat_no']));
    }

    private function serialize(MealOrder $o): array
    {
        return [
            'id'             => $o->id,
            'order_no'       => $o->order_no,
            'passenger_name' => $o->passenger_name,
            'pnr'            => $o->pnr,
            'flight_id'      => $o->flight_id,
            'flight_no'      => $o->flight?->flight_no,
            'seat_no'        => $o->seat_no,
            'meal_type'      => $o->meal_type,
            'meal_type_name' => $o->mealTypeName(),
            'is_restricted'  => $o->is_restricted,
            'status'         => $o->status,
            'notes'          => $o->notes,
            'task'           => $o->task ? [
                'id'            => $o->task->id,
                'flight_id'     => $o->task->flight_id,
                'seat_no'       => $o->task->seat_no,
                'status'        => $o->task->status,
                'label_version' => $o->task->label_version,
            ] : null,
            'active_item'    => $o->activeItem ? [
                'meal_code'     => $o->activeItem->meal_code,
                'flight_id'     => $o->activeItem->flight_id,
                'seat_no'       => $o->activeItem->seat_no,
                'label_version' => $o->activeItem->label_version,
                'status'        => $o->activeItem->status,
            ] : null,
        ];
    }
}
