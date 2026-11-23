<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Feedback;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function store(Request $request)
    {
        // Accept both singular and plural from the frontend, map to DB 'comments'
        $request->merge([
            'comments' => $request->input('comments') ?? $request->input('comment'),
        ]);

        $validated = $request->validate([
            'order_id' => 'required',
            'rating' => 'required|integer|min:1|max:5',
            'comments' => 'nullable|string',
        ]);

        // Resolve the Order safely (whether frontend sends BIGINT or UUID)
        $orderIdentifier = (string) $validated['order_id'];
        $order = is_numeric($orderIdentifier)
            ? (Order::find((int) $orderIdentifier) ?? Order::where('uuid', $orderIdentifier)->first())
            : Order::where('uuid', $orderIdentifier)->first();

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        // Enforce the completed status rule
        if ($order->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'You can only leave feedback for completed orders.',
            ], 422);
        }

        // Check for duplicate reviews manually to avoid fatal DB exceptions
        if (Feedback::where('order_id', $order->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Feedback has already been submitted for this order.',
            ], 422);
        }

        $userId = auth('sanctum')->id()
            ?? $request->user('sanctum')?->id
            ?? $order->user_id
            ?? ($request->filled('user_id') ? (int) $request->input('user_id') : null);

        // Save to database using the strictly required BIGINT
        $feedback = Feedback::create([
            'order_id' => $order->id,
            'user_id' => $userId,
            'rating' => $validated['rating'],
            'comments' => $validated['comments'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully',
            'data' => $feedback,
        ]);
    }

    /**
     * Get aggregate rating averages and latest customer feedback comments.
     * Public route.
     */
    public function summary(Request $request): JsonResponse
    {
        $totalReviews = Feedback::count();
        $averageRating = $totalReviews > 0 ? round((float) Feedback::avg('rating'), 2) : 0.0;

        $perPage = $request->integer('per_page', 10);

        $paginator = Feedback::with(['user:id,name', 'order:id,uuid'])
            ->latest('id')
            ->paginate($perPage);

        $formattedComments = $paginator->getCollection()->map(function ($item) {
            return [
                'id' => $item->id,
                'order_id' => $item->order_id,
                'order_uuid' => $item->order?->uuid,
                'user_name' => $item->user?->name ?? 'Guest Customer',
                'rating' => (int) $item->rating,
                'comments' => $item->comments,
                'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
                'formatted_date' => $item->created_at ? $item->created_at->format('M d, Y • h:i A') : '',
            ];
        });

        return ApiResponse::success([
            'average_rating' => $averageRating,
            'total_reviews' => $totalReviews,
            'total_feedback' => $totalReviews,
            'rating_breakdown' => [
                '5' => Feedback::where('rating', 5)->count(),
                '4' => Feedback::where('rating', 4)->count(),
                '3' => Feedback::where('rating', 3)->count(),
                '2' => Feedback::where('rating', 2)->count(),
                '1' => Feedback::where('rating', 1)->count(),
            ],
            'comments' => $formattedComments,
            'feedbacks' => $formattedComments,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Feedback summary retrieved successfully');
    }
}
