<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        $request->validate([
            'date' => ['sometimes', 'date'],
        ]);

        $query = Order::query()->with(['cashier', 'items', 'payment']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->string('date')->toString());
        }

        if ($request->filled('cashier_id')) {
            $query->where('user_id', $request->integer('cashier_id'));
        }

        return OrderResource::collection(
            $query->latest('id')->paginate(15)->withQueryString()
        );
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load('cashier', 'items', 'payment'));
    }

    public function pay(Order $order, PayOrderRequest $request): OrderResource
    {
        if ($order->status !== 'pending') {
            abort(Response::HTTP_CONFLICT, 'Order is not pending.');
        }

        $paidAmount = (float) $request->validated('paid_amount');

        if ($paidAmount < $order->total) {
            throw ValidationException::withMessages([
                'paid_amount' => sprintf(
                    'Paid amount is less than the order total of %s.',
                    number_format($order->total, 0, ',', '.')
                ),
            ]);
        }

        DB::transaction(function () use ($order, $paidAmount) {
            $order->payment()->create([
                'method' => 'cash',
                'amount' => $order->total,
                'received_amount' => $paidAmount,
                'change_amount' => round($paidAmount - $order->total, 2),
                'paid_at' => now(),
            ]);

            $order->update(['status' => 'paid']);
        });

        return new OrderResource($order->load('cashier', 'items', 'payment'));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $items = $request->validated('items');
        $products = $this->productsForItems($items);

        $order = DB::transaction(function () use ($request, $items, $products) {
            ['subtotal' => $subtotal, 'items' => $orderItems] = $this->buildOrderItems($items, $products);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $request->user()->id,
                'subtotal' => $subtotal,
                'discount' => 0,
                'tax' => 0,
                'total' => $subtotal,
                'status' => 'pending',
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });

        return (new OrderResource($order->load('cashier', 'items', 'payment')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        if ($order->status !== 'pending') {
            abort(Response::HTTP_CONFLICT, 'Order is not pending.');
        }

        $items = $request->validated('items');
        $products = $this->productsForItems($items);

        DB::transaction(function () use ($order, $items, $products) {
            ['subtotal' => $subtotal, 'items' => $orderItems] = $this->buildOrderItems($items, $products);

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            $order->items()->delete();
            $order->items()->createMany($orderItems);
        });

        return new OrderResource($order->load('cashier', 'items', 'payment'));
    }

    public function cancel(Order $order): OrderResource
    {
        if ($order->status === 'cancelled') {
            abort(Response::HTTP_CONFLICT, 'Order is already cancelled.');
        }

        if ($order->status !== 'pending') {
            abort(Response::HTTP_CONFLICT, 'Paid orders cannot be cancelled.');
        }

        $order->update(['status' => 'cancelled']);

        return new OrderResource($order->load('cashier', 'items', 'payment'));
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return Collection<int, Product>
     */
    private function productsForItems(array $items): Collection
    {
        $this->ensureNoDuplicateProducts($items);

        $products = Product::whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        $this->ensureProductsAreAvailable($items, $products);

        return $products;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  Collection<int, Product>  $products
     * @return array{subtotal: float, items: array<int, array{product_id: int, product_name: string, price: float, quantity: int, subtotal: float}>}
     */
    private function buildOrderItems(array $items, Collection $products): array
    {
        $subtotal = 0;
        $orderItems = [];

        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            $lineSubtotal = round($product->price * $item['quantity'], 2);

            $subtotal += $lineSubtotal;

            $orderItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'price' => $product->price,
                'quantity' => $item['quantity'],
                'subtotal' => $lineSubtotal,
            ];
        }

        return ['subtotal' => $subtotal, 'items' => $orderItems];
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  Collection<int, Product>  $products
     */
    private function ensureProductsAreAvailable(array $items, $products): void
    {
        $errors = [];

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);

            if ($product && ! $product->is_active) {
                $errors["items.{$index}.product_id"] = "Product [{$product->name}] is not available.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    private function ensureNoDuplicateProducts(array $items): void
    {
        $requestedIds = array_column($items, 'product_id');

        if (count($requestedIds) !== count(array_unique($requestedIds))) {
            throw ValidationException::withMessages([
                'items' => 'Duplicate products are not allowed. Merge quantities for the same product.',
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        return sprintf('ORD-%s-%s', now()->format('Ymd'), strtoupper(Str::random(5)));
    }
}
