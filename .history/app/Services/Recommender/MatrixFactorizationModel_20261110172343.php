<?php

namespace App\Services\Recommender;

/**
 * 3) MATRIX FACTORIZATION (SVD có bias, huấn luyện bằng SGD - còn gọi là FunkSVD / BiasedMF).
 *
 * Mỗi user u có vector ẩn p_u, mỗi phim i có vector ẩn q_i (cùng số chiều = "factors").
 * Dự đoán:   r^(u,i) = mu + b_u + b_i + p_u . q_i
 * Hàm mất mát (có chính quy hoá L2):
 *     L = sum (r - r^)^2 + reg * (b_u^2 + b_i^2 + |p_u|^2 + |q_i|^2)
 * Mỗi epoch duyệt ngẫu nhiên từng rating, với sai số e = r - r^ cập nhật:
 *     b_u += lr * (e - reg * b_u)            b_i += lr * (e - reg * b_i)
 *     p_u += lr * (e * q_i - reg * p_u)      q_i += lr * (e * p_u - reg * q_i)
 */
class MatrixFactorizationModel extends AbstractModel
{
    private array $uPos = [];      // userId => vị trí hàng trong P
    private array $iPos = [];      // itemId => vị trí hàng trong Q
    private array $P = [];         // [userIdx][factor]
    private array $Q = [];         // [itemIdx][factor]
    private array $userBias = [];  // theo userIdx
    private array $itemBias = [];  // theo itemIdx

    public function __construct(
        private int $factors = 20,
        private int $epochs = 30,
        private float $lr = 0.01,
        private float $reg = 0.05,
        private int $seed = 42,
        int $minSupport = 20
    ) {
        parent::__construct($minSupport);
    }

    public function name(): string
    {
        return 'Matrix factorization (SVD)';
    }

    public function fit(array $train, ?callable $onEpoch = null): void
    {
        $this->indexRatings($train);

        $userIds = array_keys($this->userItems);
        sort($userIds);
        $itemIds = array_keys($this->itemCount);
        sort($itemIds);
        $this->uPos = array_flip($userIds);
        $this->iPos = array_flip($itemIds);

        $nu = count($userIds);
        $ni = count($itemIds);
        $F = $this->factors;
        $mu = $this->mu;
        $lr = $this->lr;
        $reg = $this->reg;

        // Khởi tạo ngẫu nhiên nhỏ (cố định seed để kết quả lặp lại được)
        mt_srand($this->seed);
        $P = [];
        for ($u = 0; $u < $nu; $u++) {
            $row = [];
            for ($f = 0; $f < $F; $f++) {
                $row[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.2;
            }
            $P[$u] = $row;
        }
        $Q = [];
        for ($i = 0; $i < $ni; $i++) {
            $row = [];
            for ($f = 0; $f < $F; $f++) {
                $row[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.2;
            }
            $Q[$i] = $row;
        }
        $bu = array_fill(0, $nu, 0.0);
        $bi = array_fill(0, $ni, 0.0);

        // Đổi dữ liệu train sang chỉ số liên tục để duyệt nhanh
        $us = [];
        $is = [];
        $rs = [];
        foreach ($train as $row) {
            $us[] = $this->uPos[$row[0]];
            $is[] = $this->iPos[$row[1]];
            $rs[] = (float) $row[2];
        }
        $total = count($rs);
        $order = range(0, $total - 1);

        for ($epoch = 1; $epoch <= $this->epochs; $epoch++) {
            shuffle($order);
            $sq = 0.0;

            foreach ($order as $t) {
                $u = $us[$t];
                $i = $is[$t];
                $p = $P[$u];
                $q = $Q[$i];

                $dot = 0.0;
                for ($f = 0; $f < $F; $f++) {
                    $dot += $p[$f] * $q[$f];
                }
                $e = $rs[$t] - ($mu + $bu[$u] + $bi[$i] + $dot);
                $sq += $e * $e;

                $bu[$u] += $lr * ($e - $reg * $bu[$u]);
                $bi[$i] += $lr * ($e - $reg * $bi[$i]);
                for ($f = 0; $f < $F; $f++) {
                    $pf = $p[$f];
                    $qf = $q[$f];
                    $p[$f] = $pf + $lr * ($e * $qf - $reg * $pf);
                    $q[$f] = $qf + $lr * ($e * $pf - $reg * $qf);
                }
                $P[$u] = $p;
                $Q[$i] = $q;
            }

            if ($onEpoch !== null) {
                $onEpoch($epoch, sqrt($sq / $total));
            }
        }

        $this->P = $P;
        $this->Q = $Q;
        $this->userBias = $bu;
        $this->itemBias = $bi;
    }

    public function predict(int $userId, int $itemId): float
    {
        $u = $this->uPos[$userId] ?? null;
        $i = $this->iPos[$itemId] ?? null;

        $x = $this->mu;
        if ($u !== null) {
            $x += $this->userBias[$u];
        }
        if ($i !== null) {
            $x += $this->itemBias[$i];
        }
        if ($u !== null && $i !== null) {
            $p = $this->P[$u];
            $q = $this->Q[$i];
            for ($f = 0, $F = count($p); $f < $F; $f++) {
                $x += $p[$f] * $q[$f];
            }
        }

        return $this->clamp($x);
    }
}
