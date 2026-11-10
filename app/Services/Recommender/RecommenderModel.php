<?php

namespace App\Services\Recommender;

/**
 * Giao diện chung cho cả 3 mô hình gợi ý: Popularity, Item-based CF, Matrix Factorization.
 * Dữ liệu train/test là mảng các dòng [userId, itemId, rating].
 */
interface RecommenderModel
{
    /** Tên hiển thị của mô hình (dùng trong bảng kết quả). */
    public function name(): string;

    /**
     * Huấn luyện mô hình.
     * @param array<int, array{0:int,1:int,2:float}> $train
     * @param callable|null $onEpoch  Gọi sau mỗi epoch: fn(int $epoch, float $trainRmse). Chỉ MF dùng.
     */
    public function fit(array $train, ?callable $onEpoch = null): void;

    /** Dự đoán rating (đã giới hạn trong khoảng 1..5) của user cho item. */
    public function predict(int $userId, int $itemId): float;

    /**
     * Top-K phim gợi ý cho user (loại các phim user đã chấm trong tập train).
     * @return array<int,float> [itemId => điểm dự đoán], sắp xếp giảm dần
     */
    public function recommend(int $userId, int $k): array;
}
