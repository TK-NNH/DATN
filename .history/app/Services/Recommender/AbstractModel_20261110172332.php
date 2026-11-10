<?php

namespace App\Services\Recommender;

/**
 * Phần dùng chung: lập chỉ mục dữ liệu train, điểm trung bình, bias của user/item.
 */
abstract class AbstractModel implements RecommenderModel
{
    protected const MIN_RATING = 1.0;
    protected const MAX_RATING = 5.0;

    /** Chỉ những phim có ít nhất ngần này lượt chấm (trong tập train) mới được đưa vào danh sách ứng viên gợi ý. */
    protected int $minSupport;

    /** [userId => [itemId => rating]] - chỉ gồm dữ liệu train. */
    protected array $userItems = [];

    /** [itemId => số lượt chấm trong tập train]. */
    protected array $itemCount = [];

    /** Danh sách itemId đủ điều kiện làm ứng viên gợi ý. */
    protected array $candidates = [];

    /** Điểm trung bình toàn cục. */
    protected float $mu = 0.0;

    /** Bias của user và item (theo id gốc), dùng cho baseline mu + bu + bi. */
    protected array $bu = [];
    protected array $bi = [];

    public function __construct(int $minSupport = 20)
    {
        $this->minSupport = $minSupport;
    }

    protected function indexRatings(array $train): void
    {
        $this->userItems = [];
        $this->itemCount = [];
        $sum = 0.0;

        foreach ($train as $row) {
            [$u, $i, $r] = $row;
            $this->userItems[$u][$i] = $r;
            $this->itemCount[$i] = ($this->itemCount[$i] ?? 0) + 1;
            $sum += $r;
        }

        $this->mu = count($train) > 0 ? $sum / count($train) : 3.0;

        $this->candidates = [];
        foreach ($this->itemCount as $i => $c) {
            if ($c >= $this->minSupport) {
                $this->candidates[] = $i;
            }
        }
        sort($this->candidates);
    }

    /**
     * Tính bias có "damping" (kéo về 0 khi ít dữ liệu):
     *   bi = tổng(r - mu) / (n + lambdaI)
     *   bu = tổng(r - mu - bi) / (n + lambdaU)
     */
    protected function fitBiases(array $train, float $lambdaI = 25.0, float $lambdaU = 10.0): void
    {
        $sumI = [];
        $cntI = [];
        foreach ($train as $row) {
            [, $i, $r] = $row;
            $sumI[$i] = ($sumI[$i] ?? 0.0) + ($r - $this->mu);
            $cntI[$i] = ($cntI[$i] ?? 0) + 1;
        }
        $this->bi = [];
        foreach ($sumI as $i => $s) {
            $this->bi[$i] = $s / ($cntI[$i] + $lambdaI);
        }

        $sumU = [];
        $cntU = [];
        foreach ($train as $row) {
            [$u, $i, $r] = $row;
            $sumU[$u] = ($sumU[$u] ?? 0.0) + ($r - $this->mu - $this->bi[$i]);
            $cntU[$u] = ($cntU[$u] ?? 0) + 1;
        }
        $this->bu = [];
        foreach ($sumU as $u => $s) {
            $this->bu[$u] = $s / ($cntU[$u] + $lambdaU);
        }
    }

    /** Dự đoán cơ sở: mu + bu + bi (không dùng thông tin tương tác cá nhân hoá). */
    protected function baseline(int $userId, int $itemId): float
    {
        return $this->mu + ($this->bu[$userId] ?? 0.0) + ($this->bi[$itemId] ?? 0.0);
    }

    protected function clamp(float $x): float
    {
        return max(self::MIN_RATING, min(self::MAX_RATING, $x));
    }

    /** Mặc định: chấm điểm mọi phim ứng viên chưa xem rồi lấy top-K. */
    public function recommend(int $userId, int $k): array
    {
        $seen = $this->userItems[$userId] ?? [];
        $scores = [];
        foreach ($this->candidates as $i) {
            if (isset($seen[$i])) {
                continue;
            }
            $scores[$i] = $this->predict($userId, $i);
        }
        arsort($scores);

        return array_slice($scores, 0, $k, true);
    }

    // ---- Các hàm tiện ích cho demo ----

    /** Các phim user đã chấm trong dữ liệu mà mô hình biết: [itemId => rating]. */
    public function ratingsOf(int $userId): array
    {
        return $this->userItems[$userId] ?? [];
    }

    public function knowsUser(int $userId): bool
    {
        return isset($this->userItems[$userId]);
    }

    /** @return array{0:int,1:int} [userId nhỏ nhất, userId lớn nhất] */
    public function userRange(): array
    {
        $ids = array_keys($this->userItems);

        return $ids ? [min($ids), max($ids)] : [0, 0];
    }
}
