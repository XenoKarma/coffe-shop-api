<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function sales(): JsonResponse
    {
        return response()->json([
            'data' => [
                'today' => $this->summary(fn (Builder $query) => $query->whereDate('created_at', today())),
                'month' => $this->summary(fn (Builder $query) => $query->where('created_at', '>=', now()->startOfMonth())),
                'total' => $this->summary(),
                'best_sellers' => $this->bestSellers(),
            ],
        ]);
    }

    /**
     * Sum gross sales and count orders, restricted to paid orders.
     */
    private function summary(?Closure $scope = null): array
    {
        $query = Order::query()->where('status', 'paid');

        if ($scope !== null) {
            $scope($query);
        }

        $row = $query
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total), 0) as sales')
            ->first();

        return [
            'sales' => (float) $row->sales,
            'orders' => (int) $row->order_count,
        ];
    }

    /**
     * Top selling products across paid orders, ranked by quantity.
     *
     * @return array<int, array{product_id: int, product_name: string, quantity: int, sales: float}>
     */
    private function bestSellers(): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'paid')
            ->selectRaw('order_items.product_id, order_items.product_name, SUM(order_items.quantity) as quantity, SUM(order_items.subtotal) as sales')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'sales' => (float) $item->sales,
            ])
            ->all();
    }
}
