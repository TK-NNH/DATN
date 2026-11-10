<?php

namespace App\Services\Recommender;

use RuntimeException;

/**
 * 2) COLLABORATIVE FILTERING (item-based, k láng giềng gần nhất).
 *
 * Ý tưởng: hai phim "giống nhau" nếu những người chấm cả hai có xu hướng chấm tương tự.
 *
 * Bước 1 - phần dư: e(u,i) = r(u,i) - baseline(u,i)  (loại bỏ ảnh hưởng người dễ/khó tính, phim hay/dở)
 * Bước 2 - độ tương đồng cosine giữa hai phim i, j trên phần dư, có "shrinkage" để phạt cặp ít người chấm chung:
 *            sim(i,j) = cos(e_i, e_j) * c/(c + shrinkage),   c = số người chấm cả i lẫn j
 * Bước 3 - giữ lại tối đa N láng giềng có sim > 0 cho mỗi phim.
 * Bước 4 - dự đoán: r^(u,i) = baseline(u,i) + sum(sim(i,j) * e(u,j)) / (sum(sim(i,j)) + damping)
 *          lấy tối đa k phim j (láng giềng của i) mà user u đã chấm.
 */
class ItemKnnModel extends AbstractModel
{
    /** [itemId => [neighborItemId => sim]] sắp xếp giảm dần theo sim. */
    private array $neighbors = [];

    public function __construct(
        private int $neighborCount = 100,
        private float $shrinkage = 10.0,
        private int $kNearest = 30,
        private float $damping = 0.5,
        int $minSupport = 20
    ) {
        parent::__construct($minSupport);
    }

    public function name(): string
    {
        return 'Người cùng gu cũng thích (Lọc cộng tác)';
    }

    public function fit(array $train, ?callable $onEpoch = null): void
    {
        $this->indexRatings($train);
        $this->fitBiases($train);

        $itemIds = array_keys($this->itemCount);
        sort($itemIds);
        $n = count($itemIds);

        if ($n * $n > 12000000) {
            throw new RuntimeException(
                "Có {$n} phim, ma trận tương đồng quá lớn cho PHP. Hãy dùng MovieLens 100K (1.682 phim)."
            );
        }

        $pos = array_flip($itemIds);               // itemId => vị trí 0..n-1
        $dot = array_fill(0, $n * $n, 0.0);        // tích vô hướng phần dư của từng cặp phim
        $cnt = array_fill(0, $n * $n, 0);          // số người chấm chung từng cặp
        $norm = array_fill(0, $n, 0.0);            // tổng bình phương phần dư của từng phim

        foreach ($this->userItems as $u => $items) {
            $idx = [];
            $res = [];
            foreach ($items as $i => $r) {
                $idx[] = $pos[$i];
                $res[] = $r - $this->baseline($u, $i);
            }
            $m = count($idx);
            for ($a = 0; $a < $m; $a++) {
                $ia = $idx[$a];
                $ea = $res[$a];
                $norm[$ia] += $ea * $ea;
                for ($b = $a + 1; $b < $m; $b++) {
                    $ib = $idx[$b];
                    $key = $ia < $ib ? $ia * $n + $ib : $ib * $n + $ia;
                    $dot[$key] += $ea * $res[$b];
                    $cnt[$key]++;
                }
            }
        }

        $this->neighbors = [];
        for ($a = 0; $a < $n; $a++) {
            $sims = [];
            for ($b = 0; $b < $n; $b++) {
                if ($a === $b) {
                    continue;
                }
                $key = $a < $b ? $a * $n + $b : $b * $n + $a;
                $c = $cnt[$key];
                if ($c === 0) {
                    continue;
                }
                $den = sqrt($norm[$a] * $norm[$b]);
                if ($den <= 0.0) {
                    continue;
                }
                $s = ($dot[$key] / $den) * ($c / ($c + $this->shrinkage));
                if ($s > 0.0) {
                    $sims[$itemIds[$b]] = $s;
                }
            }
            arsort($sims);
            $this->neighbors[$itemIds[$a]] = array_slice($sims, 0, $this->neighborCount, true);
        }
    }

    public function predict(int $userId, int $itemId): float
    {
        return $this->clamp($this->rawPredict($userId, $itemId));
    }

    protected function rawPredict(int $userId, int $itemId): float
    {
        $pred = $this->baseline($userId, $itemId);
        $rated = $this->userItems[$userId] ?? [];

        $num = 0.0;
        $den = 0.0;
        $used = 0;
        foreach ($this->neighbors[$itemId] ?? [] as $j => $sim) {
            if (!isset($rated[$j])) {
                continue;
            }
            $num += $sim * ($rated[$j] - $this->baseline($userId, $j));
            $den += $sim;
            if (++$used >= $this->kNearest) {
                break;
            }
        }

        if ($den > 0.0) {
            $pred += $num / ($den + $this->damping);
        }

        return $pred;
    }
}
