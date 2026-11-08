<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Lỗi ở giao diện web -> chuyển về trang chủ kèm thông báo thay vì hiện trang lỗi.
        // Lỗi vẫn được ghi vào storage/logs/laravel.log như bình thường.
        $this->renderable(function (Throwable $e, $request) {
            // API (JSON) giữ nguyên cách trả lỗi cũ
            if ($request->expectsJson() || $request->is('api/*')) {
                return null;
            }
            // Không có session thì không thể gắn thông báo
            if (!$request->hasSession()) {
                return null;
            }
            // Để Laravel tự xử lý: lỗi validate (quay lại form kèm lỗi) và chưa đăng nhập (chuyển tới trang login)
            if ($e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }
            // Chính trang chủ bị lỗi thì không chuyển hướng nữa, tránh lặp vô hạn
            if ($request->is('/')) {
                return null;
            }

            return redirect()->route('movies.index')->with('system_error', $this->friendlyMessage($e));
        });
    }

    private function friendlyMessage(Throwable $e): string
    {
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        return match ($status) {
            403     => 'Bạn không có quyền truy cập chức năng này.',
            404     => 'Không tìm thấy nội dung bạn yêu cầu.',
            419     => 'Phiên làm việc đã hết hạn. Vui lòng thử lại.',
            429     => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.',
            default => 'Hệ thống đang gặp sự cố. Vui lòng thử lại sau.',
        };
    }
}
