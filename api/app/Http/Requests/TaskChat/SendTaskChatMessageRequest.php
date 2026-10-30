<?php

namespace App\Http\Requests\TaskChat;

use App\Ai\Prompts\TaskChatPrompt;
use Illuminate\Foundation\Http\FormRequest;

class SendTaskChatMessageRequest extends FormRequest
{
    // Permission is checked in the controller (TaskPolicy::chat).
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // required fails on a message of only spaces (the request trims strings).
        return [
            'message' => ['required', 'string', 'max:'.TaskChatPrompt::MAX_MESSAGE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Write a question first.',
            'message.max' => 'Keep it under '.TaskChatPrompt::MAX_MESSAGE_LENGTH.' characters.',
        ];
    }
}
