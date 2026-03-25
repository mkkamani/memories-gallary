<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Album;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AlbumTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_albums_index()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->get(route('albums.index'));
        
        $response->assertStatus(200);
    }

    public function test_user_can_create_album()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->post(route('albums.store'), [
            'title' => 'Test Album',
            'description' => 'Test Description',
            'is_public' => true,
        ]);
        
        $response->assertRedirect(route('albums.index'));
        $this->assertDatabaseHas('albums', [
            'title' => 'Test Album',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_view_own_album()
    {
        $user = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $user->id]);
        
        $response = $this->actingAs($user)->get(route('albums.show', ['path' => $album->path]));
        
        $response->assertStatus(200);
    }

    public function test_user_can_view_public_album()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $album = Album::factory()->create([
            'user_id' => $otherUser->id,
            'is_public' => true,
        ]);
        
        $response = $this->actingAs($user)->get(route('albums.show', ['path' => $album->path]));
        
        $response->assertStatus(200);
    }

    public function test_user_cannot_view_private_album_of_others()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $album = Album::factory()->create([
            'user_id' => $otherUser->id,
            'is_public' => false,
        ]);
        
        $response = $this->actingAs($user)->get(route('albums.show', ['path' => $album->path]));
        
        $response->assertStatus(200);
    }

    public function test_album_index_shows_last_five_media_from_nested_subalbums()
    {
        $user = User::factory()->create();

        $root = Album::factory()->create(['user_id' => $user->id, 'location' => 'Rajkot']);
        $child = Album::factory()->create(['user_id' => $user->id, 'parent_id' => $root->id, 'location' => 'Rajkot']);
        $grandchild = Album::factory()->create(['user_id' => $user->id, 'parent_id' => $child->id, 'location' => 'Rajkot']);

        Media::factory()->create(["user_id" => $user->id, "album_id" => $root->id, "file_name" => "root-6.jpg", "created_at" => now()->subMinutes(6)]);
        Media::factory()->create(["user_id" => $user->id, "album_id" => $child->id, "file_name" => "child-5.jpg", "created_at" => now()->subMinutes(5)]);
        Media::factory()->create(["user_id" => $user->id, "album_id" => $grandchild->id, "file_name" => "grandchild-4.jpg", "created_at" => now()->subMinutes(4)]);
        Media::factory()->create(["user_id" => $user->id, "album_id" => $root->id, "file_name" => "root-3.jpg", "created_at" => now()->subMinutes(3)]);
        Media::factory()->create(["user_id" => $user->id, "album_id" => $child->id, "file_name" => "child-2.jpg", "created_at" => now()->subMinutes(2)]);
        Media::factory()->create(["user_id" => $user->id, "album_id" => $grandchild->id, "file_name" => "grandchild-1.jpg", "created_at" => now()->subMinute()]);

        $response = $this->actingAs($user)->get(route('albums.index'));

        $response->assertStatus(200);

        $inertia = AssertableInertia::fromTestResponse($response);
        $data = $inertia->toArray();
        $albums = collect($data['props']['albums']);

        $rootAlbum = $albums->firstWhere('id', $root->id);
        $this->assertNotNull($rootAlbum);
        $this->assertSame(6, $rootAlbum['media_count']);
        $this->assertCount(5, $rootAlbum['preview_media']);
        $this->assertSame('grandchild-1.jpg', $rootAlbum['preview_media'][0]['file_name']);
    }
}
