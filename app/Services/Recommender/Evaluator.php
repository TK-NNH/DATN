<?php

namespace App\Services\Recommender;

/**
 * Đánh giá một mô hình trên tập test.
 *
 * - RMSE / MAE: sai số giữa rating dự đoán và rating thật của TOÀN BỘ tập test.
 *       RMSE = sqrt( trung bình (dự đoán - thật)^2 )
 * - Precision@K / Recall@K: chất lượng danh sách top-K gợi ý.
 *     + Phim "đúng gu" của user = phim user chấm >= $relevantThreshold (mặc định 4) trong tập TEST.
 *     + Danh sách gợi ý = top-K phim user CHƯA chấm trong tập train (chỉ xét phim đủ minSupport lượt chấm).
 *     + hits = số phim trong top-K nằm trong tập "đúng gu".
 *     + Precision@K = hits / K          Recall@K = hits / (số phim đúng gu của user)
 *     Kết quả là trung bình trên các user có ít nhất 1 phim đúng gu trong tập test.
 */
class Evaluator
{
    public function __construct(
        private array $ks = [5, 10, 20],
        private float $relevantThreshold = 4.0
    ) {
        sort($this->ks);
    }

    /**
     * @param array $test     các dòng [userId, itemId, rating]
     * @param int   $maxUsers 0 = đánh giá top-K trên tất cả user; >0 = chỉ lấy ngẫu nhiên ngần này user (cho nhanh)
     */
    public function evaluate(RecommenderModel $model, array $test, int $maxUsers = 0, int $seed = 42): array
    {
        $se = 0.0;
        $ae = 0.0;
        $n = 0;
        $relevant = []; // [userId => [itemId => true]]

        foreach ($test as $row) {
            [$u, $i, $r] = $row;
            $d = $model->predict($u, $i) - $r;
            $se += $d * $d;
            $ae += abs($d);
            $n++;
            if ($r >= $this->relevantThreshold) {
                $relevant[$u][$i] = true;
            }
        }

        $users = array_keys($relevant);
        sort($users);
        if ($maxUsers > 0 && count($users) > $maxUsers) {
            mt_srand($seed);
            shuffle($users);
            $users = array_slice($users, 0, $maxUsers);
        }

        $maxK = max($this->ks);
        $sumP = array_fill_keys($this->ks, 0.0);
        $sumR = array_fill_keys($this->ks, 0.0);

        foreach ($users as $u) {
            $recommended = array_keys($model->recommend($u, $maxK));
            $total = count($relevant[$u]);
            foreach ($this->ks as $k) {
                $hits = 0;
                foreach (array_slice($recommended, 0, $k) as $item) {
                    if (isset($relevant[$u][$item])) {
                        $hits++;
                    }
                }
                $sumP[$k] += $hits / $k;
                $sumR[$k] += $hits / $total;
            }
        }

        $nUsers = max(1, count($users));
        $precision = [];
        $recall = [];
        foreach ($this->ks as $k) {
            $precision[$k] = $sumP[$k] / $nUsers;
            $recall[$k] = $sumR[$k] / $nUsers;
        }

        return [
            'rmse'      => sqrt($se / max(1, $n)),
            'mae'       => $ae / max(1, $n),
            'n_test'    => $n,
            'n_users'   => count($users),
            'precision' => $precision,
            'recall'    => $recall,
        ];
    }

    public function ks(): array
    {
        return $this->ks;
    }
}
