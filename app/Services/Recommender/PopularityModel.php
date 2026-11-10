<?php

namespace App\Services\Recommender;

/**
 * 1) POPULARITY BASELINE - gợi ý theo độ phổ biến, KHÔNG cá nhân hoá.
 *
 * Điểm của phim i là trung bình có trọng số (Bayesian average):
 *     score(i) = n/(n+m) * mean(i) + m/(n+m) * mu
 * trong đó n = số lượt chấm của phim, mean(i) = điểm trung bình của phim,
 * mu = điểm trung bình toàn bộ, m = "trọng số tiên nghiệm".
 * Phim ít lượt chấm bị kéo về mu nên không vượt mặt phim nhiều lượt chấm chỉ vì có vài điểm 5.
 */
class PopularityModel extends AbstractModel
{
    /** [itemId => score] */
    private array $score = [];

    /** [itemId => score] của các phim ứng viên, sắp xếp giảm dần. */
    private array $ranking = [];

    public function __construct(private float $priorWeight = 10.0, int $minSupport = 20)
    {
        parent::__construct($minSupport);
    }

    public function name(): string
    {
        return 'Phổ biến (Popularity baseline)';
    }

    public function fit(array $train, ?callable $onEpoch = null): void
    {
        $this->indexRatings($train);

        $sum = [];
        $cnt = [];
        foreach ($train as $row) {
            [, $i, $r] = $row;
            $sum[$i] = ($sum[$i] ?? 0.0) + $r;
            $cnt[$i] = ($cnt[$i] ?? 0) + 1;
        }

        $m = $this->priorWeight;
        $this->score = [];
        foreach ($sum as $i => $s) {
            $n = $cnt[$i];
            $this->score[$i] = ($n / ($n + $m)) * ($s / $n) + ($m / ($n + $m)) * $this->mu;
        }

        $this->ranking = [];
        foreach ($this->candidates as $i) {
            $this->ranking[$i] = $this->score[$i];
        }
        arsort($this->ranking);
    }

    public function predict(int $userId, int $itemId): float
    {
        return $this->clamp($this->score[$itemId] ?? $this->mu);
    }

    public function recommend(int $userId, int $k): array
    {
        $seen = $this->userItems[$userId] ?? [];
        $out = [];
        foreach ($this->ranking as $i => $score) {
            if (isset($seen[$i])) {
                continue;
            }
            $out[$i] = $score;
            if (count($out) >= $k) {
                break;
            }
        }

        return $out;
    }
}
