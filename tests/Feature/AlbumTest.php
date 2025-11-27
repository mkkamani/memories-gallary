<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Album;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        
        $response = $this->actingAs($user)->get(route('albums.show', $album));
        
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
        
        $response = $this->actingAs($user)->get(route('albums.show', $album));
        
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
        
        $response = $this->actingAs($user)->get(route('albums.show', $album));
        
        $response->assertStatus(403);
    }
}
