<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\CartItem;
use App\Models\ProductDetail;
use App\Models\Product;
use App\Models\Payment;
use App\Models\Shipping;
use App\Services\AuthService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\GraphQL\Traits\GraphQLResponse;
use Illuminate\Support\Facades\Log;
use App\Services\GHNService;
use App\Models\UserCredential;
use App\GraphQL\Enums\OrderStatus;
use App\GraphQL\Enums\PaymentStatus;
use App\GraphQL\Enums\ShippingStatus;

final readonly class OrderResolver
{
    use GraphQLResponse;

    public function __invoke(null $_, array $args)
    {
        // TODO implement the resolver
    }
    public function updateOrderItem($_,array $args):array
    {
        $user = auth('api')->user();

        if(!isset($args['order_item_id'])){
            return $this->error('order_item_id is required', 400);
        }
        $orderItem=OrderItem::find($args['order_item_id']);
        if($orderItem===null || $orderItem->order->user_id !== $user->id){
            return $this->error('OrderItem not found', 404);
        }
        $orderItem->quantity = $args['quantity'] ?? $orderItem->quantity;
        $orderItem->save();
        return $this->success([], 'OrderItem updated successfully', 200);
    }
    public function deleteOrderItem($_,array $args):array
    {
        $user = auth('api')->user();

        if(!isset($args['order_item_id'])){
            return $this->error('order_item_id is required', 400);
        }
        $orderItem=OrderItem::find($args['order_item_id']);
        if($orderItem===null || $orderItem->order->user_id !== $user->id){
            return $this->error('OrderItem not found', 404);
        }
        $orderItem->delete();
        return $this->success([], 'OrderItem deleted successfully', 200);
    }
    public function createOrderItem($_,array $args):array
    {
        $validator = Validator::make($args, [
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:1',
            'price' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return [
                'code' => 400,
                'message' => $validator->errors()->first(),
                'orderItem' => null,
            ];
        }
        $orderItem = OrderItem::create([
            'order_id' => $args['order_id'],
            'product_id' => $args['product_id'],
            'quantity' => $args['quantity'],
            'price' => $args['price'],
        ]);
        return [
            'code' => 200,
            'message' => 'success',
            'orderItem' => $orderItem,
        ];
    }
    public function createOrder($_, array $args): array
    {
        $user = auth('api')->user();

        $validator = Validator::make($args, [
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);
        
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 400);
        }

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::PENDING,
                'total_price' => 0,
            ]);

            $total_price = 0;

            foreach ($args['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (!$product) {
                    DB::rollBack();
                    return $this->error('Product not found: ' . $item['product_id'], 404);
                }

                if ($product->stock < $item['quantity']) {
                    DB::rollBack();
                    return $this->error('Not enough stock for product: ' . $product->name, 400);
                }

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                ]);

                $product->stock -= $item['quantity'];
                $product->save();

                $total_price += $product->price * $item['quantity'];
            }

            $order->total_price = $total_price;
            $order->save();

            // Remove items from cart if typeOrder is 'cart'
            if (isset($args['typeOrder']) && $args['typeOrder'] === 'cart') {
                $this->removeItemsFromCart($user, $args['items']);
            }

            DB::commit();

            return $this->success([
                'order' => $this->formatOrderResponse($order),
            ], 'Order created successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove purchased items from user's cart
     */
    private function removeItemsFromCart($user, array $items): void
    {
        foreach ($items as $item) {
            $cartItem = $user->cartItems()
                ->where('product_id', $item['product_id'])
                ->first();
            
            if (!$cartItem) {
                continue;
            }
            
            $quantityToBuy = $item['quantity'];
            
            if ($quantityToBuy >= $cartItem->quantity) {
                // Xóa hoàn toàn nếu mua hết hoặc mua nhiều hơn
                $cartItem->delete();
                Log::info("Removed cart item completely for product_id: {$item['product_id']}");
            } else {
                // Trừ số lượng đã mua
                $oldQuantity = $cartItem->quantity;
                $cartItem->quantity -= $quantityToBuy;
                $cartItem->save();
                Log::info("Updated cart item quantity from {$oldQuantity} to {$cartItem->quantity} for product_id: {$item['product_id']}");
            }
        }
    }
    public function updateOrder($_,array $args):array
    {
        if(!isset($args['order_id'])){
            return [
                'code' => 400,
                'message' => 'order_id is required',
                'order' => null,
            ];
        }
        $order=Order::find($args['order_id']);
        if($order===null){
            return [
                'code' => 404,
                'message' => 'order not found',
                'order' => null,
            ];
        }
        $order->status=$args['status'] ?? $order->status;
        $order->price=$args['total_price'] ?? $order->total_price;
        $order->save();
        return [
            'code' => 200,
            'message' => 'success',
            'order' => $order,
        ];
    }
    public function deleteOrder($_,array $args):array
    {
        if(!isset($args['order_id'])){
            return [
                'code' => 400,
                'message' => 'order_id is required',
                'order' => null,
            ];
        }
        if(!isset($args['user_id'])){
            return [
                'code' => 400,
                'message' => 'user_id is required',
                'order' => null,
            ];
        }
        $order=Order::where('id',$args['order_id'])->where('user_id',$args['user_id'])->first();
        if($order===null){
            return [
                'code' => 404,
                'message' => 'order not found',
                'order' => null,
            ];
        }

        // Khôi phục lại stock cho từng sản phẩm trong order
        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);
            if ($product) {
                $product->stock += $item->quantity;
                $product->save();
            }
        }

        $order->delete();
        return [
            'code' => 200,
        'message' => 'success',
            'order' => null,
        ];
    }
    public function createOrderFromCart($_,array $args):array
    {
        $user = auth('api')->user();
        $cartItems = CartItem::where('user_id', $user->id)->get();
        if($cartItems->isEmpty()){
            return $this->error('Cart is empty', 404);
        }
        DB::beginTransaction();
        try{
            $order = Order::create([
                'user_id' => $user->id,
                'status' => OrderStatus::PENDING,
                'total_price' => 0,
            ]);
            $total_price = 0;
            foreach ($cartItems as $cartItem) {
                $product = Product::find($cartItem->product_id);
                if($product === null){
                    continue;
                }
                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'price' => $product->price,
                ]);
                $total_price += $product->price * $cartItem->quantity;
            }
            $order->total_price = $total_price;
            $order->save();
            $formattedOrder = $this->formatOrderResponse($order);
            DB::commit();
            
            return $this->success([
                'order' => $formattedOrder,
            ], 'Order created successfully', 200);
        }catch(\Exception $e){
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    private function formatOrderResponse(Order $order): array
    {
        $order->load('items.product.details');

        // Index by product_id as string            
        return [
            'id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'total_price' => (float)$order->total_price,
            'payment_status' => $order->payment_status ?? 'pending',
            'shipping_address' => $order->shipping_address ?? null,
            'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            'items' => $order->items->map(function (OrderItem $item) {

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'price' => (float)$item->price,
                    'quantity' => $item->quantity,
                    'name' => $item->product ? $item->product->name : 'Unknown Product',
                    'image' => $item->product && $item->product->details ? $item->product->details->images[0] : null,
                ];
            }),
        ];
    }
    private function updateProductStock(OrderItem $orderItem): void
    {
        $product = Product::find($orderItem->product_id);
        if ($product) {
            $product->stock -= $orderItem->quantity;
            $product->save();
        }
    }
    private function restoreProductStock(OrderItem $orderItem): void
    {
        $product = Product::find($orderItem->product_id);
        if ($product) {
            $product->stock += $orderItem->quantity;
            $product->save();
        }
    }

    public function processingOrder($_,array $args):array
    {
        $user = auth('api')->user();
        
        // Only admin or staff can process orders
        if (!$user->isAdmin() && !$user->isStaff()) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== OrderStatus::CONFIRMED) {
            return $this->error('Order must be confirmed before processing', 400);
        }
        
        $order->status = OrderStatus::PROCESSING;
        $order->save();
        
        return $this->success([
            'order' => $this->formatOrderResponse($order)
        ], 'Order processing started', 200);
    }

    public function completeDelivery($_, array $args): array
    {
        $user = auth('api')->user();
        
        if (!$user || (!$user->isAdmin() && !$user->isStaff())) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== OrderStatus::SHIPPING) {
            return $this->error('Order must be shipping before completion', 400);
        }

        DB::beginTransaction();
        try {
            $payment = Payment::where('order_id', $order->id)->first();
            $shipping = Shipping::where('order_id', $order->id)->first();

            // Validate that shipping exists
            if (!$shipping) {
                DB::rollBack();
                return $this->error('Shipping information not found for this order', 404);
            }

            // Update shipping status
            $shipping->status = ShippingStatus::DELIVERED;
            $shipping->save();

            // If COD, mark payment as completed
            if ($payment && $payment->payment_method === 'cod') {
                $payment->payment_status = PaymentStatus::COMPLETED;
                $payment->save();
            }

            // Complete order
            $order->status = OrderStatus::COMPLETED;
            $order->save();

            DB::commit();
            
            return $this->success([
                'order' => $this->formatOrderResponse($order),
            ], 'Order completed successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    public function cancelOrder($_,array $args):array
    {
        $user = auth('api')->user();
        
        // Only admin or staff can cancel orders
        if (!$user->isAdmin() && !$user->isStaff()) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        $cancellableStatuses = [
            OrderStatus::PENDING,
            OrderStatus::CONFIRMED,
            OrderStatus::PROCESSING
        ];

        if (!in_array($order->status, $cancellableStatuses)) {
            return $this->error('Order cannot be cancelled at this stage', 400);
        }

        DB::beginTransaction();
        try {
            // Update order status
            $order->status = OrderStatus::CANCELLED;
            $order->save();

            // Update shipping status if exists
            $shipping = Shipping::where('order_id', $order->id)->first();
            if ($shipping) {
                $shipping->status = ShippingStatus::FAILED;
                $shipping->save();
            }

            // Restore product stock
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->stock += $item->quantity;
                    $product->save();
                }
            }

            // Handle refund if payment was completed
            $payment = Payment::where('order_id', $order->id)->first();
            if ($payment && $payment->payment_status === PaymentStatus::COMPLETED && 
                $payment->payment_method === 'vnpay') {
                    // handle refund logic here
            }

            DB::commit();
            
            return $this->success([
                'order' => $this->formatOrderResponse($order),
            ], 'Order cancelled successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    
    public function shipOrder($_,array $args):array
    {
        $user = auth('api')->user();
        
        // Only admin or staff can ship orders
        if (!$user->isAdmin() && !$user->isStaff()) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== OrderStatus::PROCESSING) {
            return $this->error('Order must be processing before shipping', 400);
        }

        DB::beginTransaction();
        try {
            // Update order status
            $order->status = OrderStatus::SHIPPING;
            $order->save();

            // Update shipping
            $shipping = Shipping::where('order_id', $order->id)->first();
            if ($shipping) {
                $shipping->status = ShippingStatus::DELIVERING;
                $shipping->save();
            }

            DB::commit();
            
            return $this->success([
                'order' => $this->formatOrderResponse($order),
            ], 'Order shipped successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
    public function confirmOrder($_,array $args):array
    {
        $user = auth('api')->user();
        
        // Only admin or staff can confirm orders
        if (!$user->isAdmin() && !$user->isStaff()) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== OrderStatus::PENDING) {
            return $this->error('Order must be pending to be confirmed', 400);
        }
        
        $order->status = OrderStatus::CONFIRMED;
        $order->save();
        
        return $this->success([
            'order' => $this->formatOrderResponse($order)
        ], 'Order confirmed successfully', 200);
    }

    public function markOrderAsFailed($_, array $args): array
    {
        $user = auth('api')->user();
        
        // Only admin or staff can mark orders as failed
        if (!$user->isAdmin() && !$user->isStaff()) {
            return $this->error('Unauthorized', 403);
        }
        
        if (!isset($args['order_id'])) {
            return $this->error('order_id is required', 400);
        }
        
        $order = Order::find($args['order_id']);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }

        if ($order->status !== OrderStatus::PENDING && 
            $order->status !== OrderStatus::CONFIRMED && 
            $order->status !== OrderStatus::PROCESSING &&
            $order->status !== OrderStatus::SHIPPING) {
                return $this->error('Order cannot be marked as failed at this stage', 400);
        }

        DB::beginTransaction();
        try {
            // Update order status
            $order->status = OrderStatus::DELIVERY_FAILED;
            $order->save();

            // Update shipping status if exists
            $shipping = Shipping::where('order_id', $order->id)->first();
            if ($shipping) {
                $shipping->status = ShippingStatus::FAILED;
                $shipping->save();
            }

            // Restore product stock
            foreach ($order->items as $item) {
                $product = Product::find($item->product_id);
                if ($product) {
                    $product->stock += $item->quantity;
                    $product->save();
                }
            }
            

            DB::commit();
            
            return $this->success([
                'order' => $this->formatOrderResponse($order),
            ], 'Order marked as failed successfully', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Internal server error: ' . $e->getMessage(), 500);
        }
    }
}
