<?php

namespace App\Http\Controllers\client;

use App\Http\Controllers\Controller;
use App\Services\MovieChatbotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatbotController extends Controller
{
    public function message(Request $request, MovieChatbotService $bot)
    {
        $data = $request->validate([
            'message' => 'required|string|max:300',
        ]);

        return response()->json($bot->reply($data['message'], Auth::user()));
    }
}
