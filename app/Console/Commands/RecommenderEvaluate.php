<?php

namespace App\Console\Commands;

use App\Services\Recommender\Evaluator;
use App\Services\Recommender\ItemKnnModel;
use App\Services\Recommender\MatrixFactorizationModel;
use App\Services\Recommender\MovieLensDataset;
use App\Services\Recommender\PopularityModel;
use App\Services\Recommender\RecommenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RecommenderEvaluate extends Command
{
    protected $signature = 'recommender:evaluate
        {--dir= : Thư mục chứa u.data, u.item (mặc định storage/app/movielens/ml-100k)}
        {--test=0.2 : Tỉ lệ rating của mỗi người dùng dùng làm tập test}
        {--seed=42 : Seed ngẫu nhiên (để kết quả lặp lại được)}
        {--users=0 : Chỉ đánh giá top-K trên ngần này người dùng (0 = tất cả)}
        {--factors=20 : Số chiều ẩn của Matrix Factorization}
        {--epochs=30 : Số epoch của Matrix Factorization}
        {--lr=0.01 : Learning rate của Matrix Factorization}
        {--reg=0.05 : Hệ số chính quy hoá của Matrix Factorization}
        {--neighbors=100 : Số láng giềng giữ lại cho mỗi phim (Item-based CF)}
        {--min-support=20 : Phim phải có ít nhất ngần này lượt chấm (tập train) mới được đưa vào danh sách gợi ý}';

    protected $description = 'Huấn luyện & đánh giá Popularity / Item-based CF / Matrix Factorization (RMSE, Precision@K, Recall@K)';

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $seed = (int) $this->option('seed');
        $minSupport = (int) $this->option('min-support');

        $dataset = new MovieLensDataset($this->option('dir') ?: null);
        $ratings = $dataset->ratings();
        [$train, $test] = MovieLensDataset::split($ratings, (float) $this->option('test'), $seed);

        $this->info(sprintf(
            'Dữ liệu: %d rating | train %d | test %d (seed %d)',
            count($ratings), count($train), count($test), $seed
        ));

        $models = [
            new PopularityModel(10.0, $minSupport),
            new ItemKnnModel((int) $this->option('neighbors'), 10.0, 30, 0.5, $minSupport),
            new MatrixFactorizationModel(
                (int) $this->option('factors'), (int) $this->option('epochs'),
                (float) $this->option('lr'), (float) $this->option('reg'), $seed, $minSupport
            ),
        ];

        $evaluator = new Evaluator([5, 10, 20], 4.0);
        $results = [];

        foreach ($models as $model) {
            $this->line('');
            $this->info('>> ' . $model->name());

            $t = microtime(true);
            $model->fit($train, function (int $epoch, float $rmse) {
                if ($epoch % 5 === 0) {
                    $this->line(sprintf('   epoch %2d  | RMSE trên tập train: %.4f', $epoch, $rmse));
                }
            });
            $fitSeconds = microtime(true) - $t;

            $t = microtime(true);
            $m = $evaluator->evaluate($model, $test, (int) $this->option('users'), $seed);
            $m['model'] = $model->name();
            $m['fit_seconds'] = round($fitSeconds, 1);
            $m['eval_seconds'] = round(microtime(true) - $t, 1);
            $results[] = $m;

            $this->line(sprintf(
                '   RMSE %.4f | MAE %.4f | huấn luyện %.1fs | đánh giá %.1fs',
                $m['rmse'], $m['mae'], $m['fit_seconds'], $m['eval_seconds']
            ));
        }

        // Bảng tổng hợp
        $header = ['Mô hình', 'RMSE', 'MAE'];
        foreach ($evaluator->ks() as $k) {
            $header[] = "P@{$k}";
            $header[] = "R@{$k}";
        }
        $rows = [];
        foreach ($results as $m) {
            $row = [$m['model'], number_format($m['rmse'], 4), number_format($m['mae'], 4)];
            foreach ($evaluator->ks() as $k) {
                $row[] = number_format($m['precision'][$k], 4);
                $row[] = number_format($m['recall'][$k], 4);
            }
            $rows[] = $row;
        }
        $this->line('');
        $this->info(sprintf(
            'KẾT QUẢ (tập test %d rating; top-K đánh giá trên %d người dùng; "đúng gu" = rating >= 4)',
            $results[0]['n_test'], $results[0]['n_users']
        ));
        $this->table($header, $rows);

        // Lưu ra file để dán vào báo cáo / hiển thị trong chatbot
        $path = RecommenderService::evaluationPath();
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode([
            'created_at' => now()->toDateTimeString(),
            'params'     => [
                'test_ratio'  => (float) $this->option('test'),
                'seed'        => $seed,
                'min_support' => $minSupport,
                'relevant'    => 4.0,
                'train'       => count($train),
                'test'        => count($test),
            ],
            'results'    => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line("Đã lưu kết quả vào: {$path}");

        return self::SUCCESS;
    }
}
