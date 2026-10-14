<?php

namespace Tests\Security;

use App\Models\Photo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;
use Tests\Support\Scenario;

/** Malicious upload attempts. Anything that is not a decodable image is refused; images are re-encoded. */
class UploadSecurityTest extends DatabaseTestCase
{
    private Scenario $s;
    private string $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Scenario::build();
        $this->s->actingAs($this->s->agent);
        $this->visit = $this->api('POST', 'visits', ['assignment_id' => $this->s->assignmentA->id, 'client_uuid' => (string) Str::uuid()])->json('data.id');
        $this->api('POST', "visits/{$this->visit}/check-in", Scenario::gps())->assertOk();
    }

    private function upload(UploadedFile $file)
    {
        return $this->api('POST', "visits/{$this->visit}/photos", ['file' => $file]);
    }

    public function test_script_files_are_refused_even_when_renamed_to_images(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('payload.exe', "MZ\x90\x00\x03"),
            UploadedFile::fake()->createWithContent('archive.zip', "PK\x03\x04"),
            UploadedFile::fake()->create('empty.jpg', 0),
        ] as $file) {
            $this->upload($file)->assertStatus(422);
        }
        $this->assertSame(0, Photo::query()->count());
        $this->assertSame([], Storage::disk('evidence')->allFiles());
    }

    public function test_oversized_uploads_are_refused(): void
    {
        $this->upload(UploadedFile::fake()->create('big.jpg', config('field.uploads.max_kb') + 1, 'image/jpeg'))->assertStatus(422);
    }

    public function test_a_valid_image_with_a_php_payload_appended_is_sanitised_by_re_encoding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($im);
        file_put_contents($path, ob_get_clean().'<?php system($_GET["c"]); ?>');
        $this->upload(new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true))->assertCreated();

        $stored = Photo::query()->firstOrFail();
        $bytes = Storage::disk('evidence')->get($stored->path);
        $this->assertStringNotContainsString('<?php', $bytes);
        $this->assertSame('image/jpeg', $stored->mime);
        $this->assertSame(hash('sha256', $bytes), $stored->sha256);
    }

    public function test_exif_gps_and_metadata_are_stripped_and_names_are_random(): void
    {
        $this->upload(UploadedFile::fake()->image('../../etc/My Holiday <script>.jpg', 300, 200))->assertCreated();
        $photo = Photo::query()->firstOrFail();

        $this->assertMatchesRegularExpression('#^visits/'.$this->visit.'/[0-9a-f-]{36}\.jpg$#', $photo->path);
        $this->assertStringNotContainsString('..', $photo->original_name);
        $this->assertStringNotContainsString('<', $photo->original_name);
        $this->assertStringNotContainsString('/', $photo->original_name);
        $bytes = Storage::disk('evidence')->get($photo->path);
        $this->assertStringNotContainsString('Exif', substr($bytes, 0, 64));
    }

    public function test_storage_paths_and_disks_are_never_exposed_to_clients(): void
    {
        $res = $this->upload(UploadedFile::fake()->image('a.jpg'))->assertCreated();
        $this->assertArrayNotHasKey('path', $res->json('data'));
        $this->assertArrayNotHasKey('disk', $res->json('data'));
        $this->assertStringStartsWith('/api/v1/photos/', $res->json('data.url'));
    }

    public function test_evidence_is_served_inline_with_hardening_headers(): void
    {
        $id = $this->upload(UploadedFile::fake()->image('a.jpg'))->json('data.id');
        $res = $this->get("/api/v1/photos/$id/file")->assertOk();
        $res->assertHeader('Content-Type', 'image/jpeg');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
    }

    public function test_question_ids_from_another_form_are_refused(): void
    {
        $other = Scenario::form();
        $qid = $other->questions()->first()->id;
        $this->upload(UploadedFile::fake()->image('a.jpg'))->assertCreated();
        $this->api('POST', "visits/{$this->visit}/photos", ['file' => UploadedFile::fake()->image('b.jpg'), 'question_id' => $qid])->assertStatus(422);
    }

    public function test_reusing_the_same_photo_on_another_visit_raises_a_security_event(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('same.jpg', file_get_contents(UploadedFile::fake()->image('x.jpg', 64, 64)->getRealPath()));
        $this->upload($file())->assertCreated();

        // A second visit (new assignment) re-submits byte-identical evidence.
        $this->api('POST', "visits/{$this->visit}/check-out", Scenario::gps())->assertOk();
        $this->api('POST', "visits/{$this->visit}/submit")->assertStatus(422);   // form requires answers; not the point here
        $second = \App\Models\Visit::query()->create([
            'assignment_id' => $this->s->assignmentA->id, 'campaign_id' => $this->s->campaignA->id, 'agent_id' => $this->s->agent->id,
            'location_id' => $this->s->locationA->id, 'status' => 'started', 'visit_date' => now()->toDateString(),
        ]);
        $this->api('POST', "visits/{$second->id}/photos", ['file' => $file()])->assertCreated();
        $this->assertDatabaseHas('security_events', ['type' => 'photo_reuse_detected']);
    }
}
