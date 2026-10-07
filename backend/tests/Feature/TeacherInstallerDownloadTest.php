<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherInstallerDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir().'/toh-downloads-'.uniqid();
        config(['toh.downloads_path' => $dir]);
        @mkdir($dir, 0777, true);
        $this->file = $dir.'/TOH Klas Teacher_9.9.9_test-setup.exe';
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir(dirname($this->file));
        parent::tearDown();
    }

    public function test_guests_cannot_download_it(): void
    {
        file_put_contents($this->file, 'installer');

        $this->get('/downloads/teacher')->assertRedirect('/login');
    }

    public function test_a_signed_in_user_gets_the_installer_when_it_has_been_uploaded(): void
    {
        file_put_contents($this->file, 'installer');

        $this->actingAs(User::factory()->create())->get('/downloads/teacher')
            ->assertOk()
            ->assertDownload('TOH Klas Teacher_9.9.9_test-setup.exe');
    }

    public function test_it_is_a_404_when_no_installer_has_been_uploaded(): void
    {
        $this->actingAs(User::factory()->create())->get('/downloads/teacher')->assertNotFound();
    }

    public function test_the_dashboard_offers_the_download_only_when_the_file_exists(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('teacherInstaller', null));

        file_put_contents($this->file, 'installer');
        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('teacherInstaller.name', 'TOH Klas Teacher_9.9.9_test-setup.exe'));
    }
}
