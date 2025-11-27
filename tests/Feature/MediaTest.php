<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Album;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_media()
    {
        Storage::fake('public');
        
        $user = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $user->id]);
        
        $file = UploadedFile::fake()->image('test.jpg');
        
        $response = $this->actingAs($user)->post(route('media.store'), [
            'files' => [$file],
            'album_id' => $album->id,
        ]);
        
        $response->assertRedirect();
        $this->assertDatabaseHas('media', [
            'album_id' => $album->id,
            'user_id' => $user->id,
            'file_type' => 'image',
        ]);
    }

    public function test_user_can_delete_own_media()
    {
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        
        $response = $this->actingAs($user)->delete(route('media.destroy', $media));
        
        $response->assertRedirect();
        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }

    public function test_user_cannot_delete_others_media()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $otherUser->id]);
        
        $response = $this->actingAs($user)->delete(route('media.destroy', $media));
        
        $response->assertStatus(403);
    }

    public function test_admin_can_delete_any_media()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $media = Media::factory()->create(['user_id' => $user->id]);
        
        $response = $this->actingAs($admin)->delete(route('media.destroy', $media));
        
        $response->assertRedirect();
        $this->assertSoftDeleted('media', ['id' => $media->id]);
    }
}
