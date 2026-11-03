<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The address of every photograph on the site.
 *
 * WHAT WENT WRONG, so it cannot happen again quietly.
 *
 * Image URLs were built by Storage::url(), which takes its root from
 * APP_URL. APP_URL was set to the production domain while the site was
 * being used at http://localhost/visitdasol/public, so every resort
 * cover, gallery photograph and room card asked a different host for
 * its picture. The paths in the database were correct and the files
 * were on disk the whole time, which is what made it hard to see: the
 * only thing wrong was the hostname in front of them.
 *
 * The fix was to ask the request where it came from instead. These
 * tests hold that: an image URL must follow the browser, not a
 * variable in a file somebody can set wrongly.
 */
class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A resort the public can actually open.
     *
     * isPubliclyVisible() wants an ACTIVE RESORT OWNER as well as an
     * active resort, so the owner is made here rather than left to the
     * factory default — without it the show page is a 404 and the test
     * proves nothing about images.
     */
    private function publicResort(array $attributes = []): Resort
    {
        $owner = User::factory()->create([
            'role' => 'resort_owner',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return Resort::factory()->active()->for($owner, 'owner')->create($attributes);
    }

    private function resortWithPhoto(): Resort
    {
        Storage::fake('public');

        $resort = $this->publicResort(['main_image' => 'resorts/1/cover/cover.jpg']);

        Storage::disk('public')->put('resorts/1/cover/cover.jpg', 'not really a jpeg');

        return $resort;
    }

    /* The address itself -------------------------------------------------- */

    public function test_an_image_url_points_at_the_public_storage_link(): void
    {
        $resort = $this->resortWithPhoto();

        $this->assertSame(
            asset('storage/resorts/1/cover/cover.jpg'),
            $resort->main_image_url,
        );
    }

    /**
     * The regression guard. APP_URL is deliberately set to somewhere
     * else; the URL must ignore it and follow the request.
     */
    public function test_an_image_url_ignores_app_url_and_follows_the_request(): void
    {
        $resort = $this->resortWithPhoto();

        config(['app.url' => 'https://a-completely-different-host.test']);
        config(['filesystems.disks.public.url' => 'https://a-completely-different-host.test/storage']);

        $url = $resort->main_image_url;

        $this->assertStringNotContainsString(
            'a-completely-different-host.test',
            $url,
            'The image URL is still being built from APP_URL. A site served from '
            .'anywhere other than APP_URL will show broken images again.',
        );

        $this->assertStringStartsWith(url('/'), $url);
    }

    /** "storage/storage/..." is the other classic way this breaks. */
    public function test_an_image_url_has_exactly_one_storage_segment(): void
    {
        $resort = $this->resortWithPhoto();

        $this->assertSame(1, substr_count($resort->main_image_url, '/storage/'));
        $this->assertStringNotContainsString('//storage', $resort->main_image_url);
        $this->assertStringNotContainsString('storage/storage', $resort->main_image_url);
    }

    /** A leading slash in a stored path must not double up. */
    public function test_a_stored_path_with_a_leading_slash_is_tolerated(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('resorts/9/cover/x.jpg', 'x');

        $resort = $this->publicResort(['main_image' => '/resorts/9/cover/x.jpg']);

        $this->assertStringNotContainsString('storage//', $resort->main_image_url);
        $this->assertStringEndsWith('/storage/resorts/9/cover/x.jpg', $resort->main_image_url);
    }

    /* The fallback --------------------------------------------------------- */

    public function test_no_photograph_gives_the_placeholder(): void
    {
        Storage::fake('public');

        $resort = $this->publicResort(['main_image' => null]);

        $this->assertSame(asset('images/placeholder.svg'), $resort->main_image_url);
    }

    /**
     * A row whose file has gone. This used to render the browser's
     * broken-image icon; it now renders the placeholder.
     */
    public function test_a_missing_file_gives_the_placeholder(): void
    {
        Storage::fake('public');

        $resort = $this->publicResort([
            'main_image' => 'resorts/1/cover/deleted-by-hand.jpg',
        ]);

        $this->assertSame(asset('images/placeholder.svg'), $resort->main_image_url);
    }

    /* Galleries and cards --------------------------------------------------- */

    public function test_resort_gallery_and_room_images_resolve_the_same_way(): void
    {
        Storage::fake('public');

        $resort = $this->publicResort();
        $room = Room::factory()->for($resort)->create();

        Storage::disk('public')->putFileAs(
            'resorts/'.$resort->id.'/gallery',
            UploadedFile::fake()->image('g.jpg'),
            'g.jpg',
        );

        Storage::disk('public')->putFileAs(
            'rooms/'.$room->id,
            UploadedFile::fake()->image('r.jpg'),
            'r.jpg',
        );

        // Through the relationship: resort_id and room_id are not
        // fillable, and nor should they be.
        $gallery = $resort->images()->create([
            'path' => 'resorts/'.$resort->id.'/gallery/g.jpg',
            'sort_order' => 0,
        ]);

        $roomImage = $room->images()->create([
            'path' => 'rooms/'.$room->id.'/r.jpg',
            'sort_order' => 0,
        ]);

        $this->assertStringEndsWith('/storage/resorts/'.$resort->id.'/gallery/g.jpg', $gallery->url);
        $this->assertStringEndsWith('/storage/rooms/'.$room->id.'/r.jpg', $roomImage->url);

        foreach ([$gallery->url, $roomImage->url] as $url) {
            $this->assertStringNotContainsString('placeholder', $url);
        }
    }

    /** The rendered page carries working addresses, not just the model. */
    public function test_the_resort_page_renders_image_urls_under_the_request_root(): void
    {
        Storage::fake('public');

        $resort = $this->publicResort(['main_image' => 'resorts/1/cover/cover.jpg']);

        Storage::disk('public')->put('resorts/1/cover/cover.jpg', 'x');

        $html = $this->get(route('resorts.show', $resort))->assertOk()->getContent();

        $this->assertStringContainsString(
            asset('storage/resorts/1/cover/cover.jpg'),
            $html,
        );
    }
}
