<?php

namespace PDMFC\PdfGallery\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PdfsUploadedFromMobile implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $userId,
        public int $saved,
        public array $documents,
        public array $newFilenames = [],
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('pdf-gallery.documents.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PdfsUploadedFromMobile';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'saved' => $this->saved,
            'documents' => $this->documents,
            'new_filenames' => $this->newFilenames,
        ];
    }
}
