<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Http\Resources\PostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PostController extends Controller
{
    public function checkSlug(Request $request)
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'ignore_id' => ['sometimes', 'integer'],
        ]);

        $base = $request->filled('slug')
            ? Str::slug($request->string('slug')->toString())
            : Str::slug($request->string('title')->toString());

        if ($base === '') {
            return response()->json([
                'message' => 'Informe "title" ou "slug" para checar.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ignoreId = $request->integer('ignore_id');

        $slug = $base;
        $suffix = 2;

        $exists = function (string $candidate) use ($ignoreId): bool {
            $query = Post::query()->where('slug', $candidate);
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
            return $query->exists();
        };

        while ($exists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 500) {
                break; // proteção para loops longos
            }
        }

        return response()->json([
            'original' => $base,
            'slug' => $slug,
            'available' => ! Post::where('slug', $base)->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists(),
        ]);
    }

    public function index(Request $request)
    {
        $search = $request->string('q')->toString();
        $categoryId = $request->integer('category_id');
        $onlyPublished = $request->boolean('published', true);

        $query = Post::query()->with(['category', 'user'])->orderByDesc('published_at');

        if ($onlyPublished) {
            $query->where('published', true);
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $posts = $query->paginate($request->integer('per_page', 10));

        return PostResource::collection($posts);
    }

    public function store(Request $request)
    {
        Log::info(['request' => $request->all()]);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:posts,slug'],
            'excerpt' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'image_path' => ['nullable', 'string'],
            'published' => ['boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        $validated['user_id'] = $request->user()?->id ?? 1; // ajuste conforme auth


        $post = Post::create($validated);

        return (new PostResource($post->load(['category', 'user'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Post $post)
    {
        return new PostResource($post->load(['category', 'user']));
    }

    public function update(Request $request, Post $post)
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:posts,slug,' . $post->id],
            'excerpt' => ['nullable', 'string'],
            'content' => ['sometimes', 'required', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'image_base64' => ['nullable', 'string'],
            'remove_image' => ['nullable', 'boolean'],
            'published' => ['boolean'],
            'published_at' => ['nullable', 'date'],
        ]);

        if ($request->boolean('remove_image') && $post->image_path) {
            Storage::disk('public')->delete($post->image_path);
            $validated['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($post->image_path) {
                Storage::disk('public')->delete($post->image_path);
            }
            $path = $request->file('image')->store('posts', 'public');
            $validated['image_path'] = $path;
        } elseif ($request->filled('image_base64')) {
            if ($post->image_path) {
                Storage::disk('public')->delete($post->image_path);
            }
            $path = $this->storeBase64Image($request->string('image_base64')->toString());
            if ($path !== null) {
                $validated['image_path'] = $path;
            }
        }

        $post->update($validated);

        return new PostResource($post->load(['category', 'user']));
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->noContent();
    }

    private function storeBase64Image(?string $base64): ?string
    {
        if ($base64 === null || $base64 === '') {
            return null;
        }

        $matches = [];
        $mime = null;
        $data = $base64;

        if (preg_match('/^data:(image\/(?:png|jpeg|webp));base64,(.+)$/i', $base64, $matches) === 1) {
            $mime = strtolower($matches[1]);
            $data = $matches[2];
        }

        $binary = base64_decode($data, true);
        if ($binary === false) {
            return null;
        }

        // Limite ~2MB
        if (strlen($binary) > 2 * 1024 * 1024) {
            return null;
        }

        if ($mime === null) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = $finfo ? finfo_buffer($finfo, $binary) : null;
            if ($finfo) {
                finfo_close($finfo);
            }
            $mime = $detected ?: 'application/octet-stream';
        }

        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (! array_key_exists($mime, $allowed)) {
            return null;
        }

        $extension = $allowed[$mime];
        $filename = 'posts/' . Str::uuid()->toString() . '.' . $extension;

        Storage::disk('public')->put($filename, $binary, 'public');
        return $filename;
    }
}

