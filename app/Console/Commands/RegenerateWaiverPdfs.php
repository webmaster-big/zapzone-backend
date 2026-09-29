<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Waiver;
use App\Services\WaiverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RegenerateWaiverPdfs extends Command
{
    protected $signature = 'waivers:regenerate-pdfs {--dry-run} {--company=} {--before=} {--limit=0}';

    protected $description = 'Rebuild stored signed-waiver PDFs from the exact waiver version each person signed, keeping the previous file';

    public function handle(WaiverService $waivers): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $disk = config('filesystems.default');
        $rebuilt = 0;
        $failed = 0;

        $query = Waiver::query()
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereNotNull('pdf_path')
            ->when($this->option('company'), fn ($q, $companyId) => $q->where('company_id', (int) $companyId))
            ->when($this->option('before'), fn ($q, $before) => $q->where('pdf_generated_at', '<', $before))
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info(($dryRun ? '[dry run] ' : '') . "{$total} signed waiver PDF(s) match.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        $query->chunkById(100, function ($chunk) use ($waivers, $disk, $limit, &$rebuilt, &$failed) {
            foreach ($chunk as $waiver) {
                if ($limit > 0 && $rebuilt >= $limit) {
                    return false;
                }

                $oldPath = $waiver->pdf_path;
                $oldHash = (string) $waiver->pdf_hash;

                try {
                    if ($oldPath && Storage::disk($disk)->exists($oldPath)) {
                        $kept = sprintf('waivers/%d/superseded/waiver-%d-%s.pdf', $waiver->company_id, $waiver->id, substr($oldHash ?: sha1((string) $waiver->id), 0, 12));
                        Storage::disk($disk)->copy($oldPath, $kept);
                    }

                    $waivers->generateAndStoreSignedPdf($waiver);
                    $waiver->refresh();

                    if ($waiver->pdf_hash && $waiver->pdf_hash !== $oldHash) {
                        $rebuilt++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Signed waiver PDF could not be rebuilt', ['waiver_id' => $waiver->id, 'error' => $e->getMessage()]);
                }
            }

            return true;
        });

        ActivityLog::log(
            'waiver_pdfs_regenerated',
            'waivers',
            sprintf('Rebuilt %d signed waiver PDF(s) from the signed waiver version; %d could not be rebuilt. Previous files were kept under superseded/.', $rebuilt, $failed),
            null,
            null,
            null,
            null,
            ['rebuilt' => $rebuilt, 'failed' => $failed]
        );

        $this->info("Rebuilt {$rebuilt} PDF(s). {$failed} could not be rebuilt. Previous files are kept under waivers/<company>/superseded/.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
