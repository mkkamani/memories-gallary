<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    album: Object,
});

const form = useForm({
    title: props.album.title,
    description: props.album.description ?? '',
    location: props.album.location ?? '',
});

const submit = () => {
    form.put(route('albums.update', props.album.slug || props.album.id));
};
</script>

<template>
    <Head title="Edit Album" />

    <AuthenticatedLayout>
        <div class="mx-auto w-full max-w-4xl space-y-6 animate-fade-in text-foreground">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <Link :href="route('albums.show', album.path || album.slug || album.id)" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-border bg-bg-card text-muted-foreground transition hover:text-foreground hover:border-primary/30">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <h1 class="text-4xl font-heading font-bold text-foreground">Edit Album</h1>
                </div>
            </div>

            <section class="overflow-hidden rounded-2xl border border-border bg-bg-card shadow-sm">
                <div class="relative h-36 w-full overflow-hidden bg-bg-elevated">
                    <div class="absolute inset-0 bg-gradient-to-br from-primary/25 via-primary/5 to-transparent"></div>
                    <div class="absolute inset-x-6 bottom-5">
                        <h2 class="text-3xl font-heading font-bold text-foreground">Update Album Details</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Editing "{{ form.title }}"</p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-6 p-6 sm:p-8">
                    <div class="space-y-2">
                        <label for="title" class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Album Title</label>
                        <input
                            id="title"
                            type="text"
                            class="h-12 w-full rounded-xl border border-border bg-bg-elevated px-4 text-sm text-foreground shadow-inner transition focus:border-primary focus:ring-2 focus:ring-primary/35 focus:ring-offset-0 focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/35"
                            v-model="form.title"
                            placeholder="e.g. Diwali 2024"
                            required
                            autofocus
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="space-y-2">
                        <label for="description" class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Description (optional)</label>
                        <textarea
                            id="description"
                            class="w-full rounded-xl border border-border bg-bg-elevated p-4 text-sm text-foreground shadow-inner transition focus:border-primary focus:ring-2 focus:ring-primary/35 focus:ring-offset-0 focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/35"
                            v-model="form.description"
                            rows="3"
                            placeholder="A short description of this album..."
                        ></textarea>
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="space-y-2">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Location</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="flex items-center gap-3 rounded-xl border border-border bg-bg-elevated px-4 py-3 text-sm text-foreground shadow-inner transition cursor-pointer hover:border-primary/40">
                                <input
                                    type="radio"
                                    name="location"
                                    value="Ahmedabad"
                                    v-model="form.location"
                                    class="h-4 w-4 border-border text-primary focus:ring-2 focus:ring-primary/35 focus:ring-offset-0"
                                    required
                                />
                                <span class="font-medium">Ahmedabad</span>
                            </label>

                            <label class="flex items-center gap-3 rounded-xl border border-border bg-bg-elevated px-4 py-3 text-sm text-foreground shadow-inner transition cursor-pointer hover:border-primary/40">
                                <input
                                    type="radio"
                                    name="location"
                                    value="Rajkot"
                                    v-model="form.location"
                                    class="h-4 w-4 border-border text-primary focus:ring-2 focus:ring-primary/35 focus:ring-offset-0"
                                    required
                                />
                                <span class="font-medium">Rajkot</span>
                            </label>
                        </div>
                        <InputError :message="form.errors.location" />
                    </div>

                    <div class="rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-primary">
                        All albums are <span class="font-bold">public</span> - visible to everyone in your team.
                    </div>

                    <div class="flex justify-end gap-3 border-t border-border pt-4">
                        <Link :href="route('albums.show', album.path || album.slug || album.id)" class="inline-flex h-11 items-center rounded-pill px-6 text-sm font-bold text-foreground transition hover:bg-bg-hover">Cancel</Link>
                        <button
                            type="submit"
                            class="inline-flex h-11 items-center rounded-pill bg-primary px-8 text-sm font-bold text-primary-foreground shadow-md transition hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="form.processing"
                        >
                            <svg v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            {{ form.processing ? 'Updating...' : 'Update Album' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
