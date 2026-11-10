<?php

namespace App\Console\Commands;

use App\Services\Recommender\ItemKnnModel;
use App\Services\Recommender\MatrixFactorizationModel;
use App\Services\Recommender\MovieLensDataset;
use App\Services\Recommender\PopularityModel;
use App\Services\Recommender\RecommenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RecommenderTrain extends Command
{
    protected $signature = 'recommender:train
        {--dir= : Thư mục chứa u.data, u.item (mặc định storage/app/movielens/ml-100k)}
        {--seed=42}
        {--factors=20}
        {--epochs=30}
        {--lr=0.01}
        {--reg=0.05}
        {--neighbors=100}
        {--min-support=20}';

    protected $description = 'Huấn luyện 3 mô hình trên TOÀN BỘ dữ liệu MovieLens và lưu lại để chatbot dùng';

    public function handle(): int
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $seed = (int) $this->option('seed');
        $minSupport = (int) $this->option('min-support');

        $dataset = new MovieLensDataset($this->option('dir') ?: null);
        $ratings = $dataset->ratings();
        $movies = $dataset->movies();
        $this->info(sprintf('Huấn luyện trên %d rating, %d phim...', count($ratings), count($movies)));

        $popularity = new PopularityModel(10.0, $minSupport);
        $cf = new ItemKnnModel((int) $this->option('neighbors'), 10.0, 30, 0.5, $minSupport);
        $mf = new MatrixFactorizationModel(
            (int) $this->option('factors'), (int) $this->option('epochs'),
            (float) $this->option('lr'), (float) $this->option('reg'), $seed, $minSupport
        );

        foreach ([$popularity, $cf, $mf] as $model) {
            $t = microtime(true);
            $model->fit($ratings);
            $this->line(sprintf('  - %s: %.1fs', $model->name(), microtime(true) - $t));
        }

        $path = RecommenderService::modelPath();
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, serialize([
            'popularity' => $popularity,
            'cf'         => $cf,
            'mf'         => $mf,
            'movies'     => $movies,
        ]));

        $this->info('Đã lưu mô hình: ' . $path . ' (' . round(filesize($path) / 1048576, 1) . ' MB)');

        return self::SUCCESS;
    }
}
