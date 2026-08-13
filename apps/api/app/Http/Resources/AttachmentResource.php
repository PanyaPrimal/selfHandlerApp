<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'created_at' => $this->created_at->toISOString(),
            'content_url' => "/api/attachments/{$this->id}/content",
        ];
    }
}
