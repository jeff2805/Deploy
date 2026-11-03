<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored public-disk path into a browser URL,
 * falling back to the placeholder image when there is no file.
 *
 * WHY THIS DOES NOT CALL Storage::url() ANY MORE.
 *
 * It used to, and every image on the site broke. Storage::url() builds
 * its answer from config('filesystems.disks.public.url'), which is
 * APP_URL with "/storage" on the end — so the address of every
 * photograph was decided by an environment variable rather than by
 * where the site was actually being served from. With APP_URL pointing
 * at the production domain, a page opened at
 * http://localhost/visitdasol/public asked a different host entirely
 * for its pictures and got nothing back. The paths in the database
 * were right, the files were on disk, and the pictures were still
 * broken.
 *
 * asset() asks the current request where it came from, so the same
 * code produces
 *
 *   http://localhost/visitdasol/public/storage/rooms/1/photo.jpg
 *
 * in XAMPP under a subdirectory, and
 *
 *   https://example.test/storage/rooms/1/photo.jpg
 *
 * in production, with nothing to configure and no host written down
 * anywhere. The placeholder below was always built this way; now both
 * branches agree.
 *
 * A REMOTE DISK IS DIFFERENT and is handled above: S3 and friends know
 * their own absolute URL, and public/storage does not point at them.
 */
trait ResolvesPublicImage
{
    protected function publicImageUrl(?string $path): string
    {
        if (blank($path)) {
            return $this->placeholderImageUrl();
        }

        // Stored paths are relative ("rooms/1/photo.jpg"). A leading
        // slash would survive into the URL as "storage//rooms".
        $path = ltrim($path, '/');

        /*
         * Only the local driver is served through the public/storage
         * link. Anything else owns its own addresses, and asking it is
         * both correct and the only thing that works.
         */
        if (config('filesystems.disks.public.driver') !== 'local') {
            return Storage::disk('public')->url($path);
        }

        /*
         * A row can outlive its file: an upload interrupted halfway, a
         * storage folder restored from an older backup, a file removed
         * by hand. The placeholder is a better answer than the
         * browser's broken-image icon, and it costs one stat on a
         * local disk.
         */
        if (! Storage::disk('public')->exists($path)) {
            return $this->placeholderImageUrl();
        }

        return asset('storage/'.$path);
    }

    /** The grey image used wherever a photograph is missing. */
    protected function placeholderImageUrl(): string
    {
        return asset('images/placeholder.svg');
    }
}
