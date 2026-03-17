<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
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
        <div class="animate-fade-in text-foreground space-y-6">

            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="font-heading font-bold text-3xl">Edit Album</h1>
                    <p class="text-sm text-muted-foreground mt-1">Update details for "{{ form.title }}"</p>
                </div>

                <Link
                    :href="route('albums.show', album.slug || album.id)"
                    class="flex items-center gap-2 h-11 px-6 rounded-pill bg-bg-elevated border border-border text-foreground font-bold text-sm shadow-sm hover:translate-y-[-2px] transition-all whitespace-nowrap"
                >
                    Back to Album
                </Link>
            </div>

            <div class="max-w-3xl">
                <div class="bg-bg-card border border-border sm:rounded-2xl p-6 sm:p-8 shadow-sm">
                    <form @submit.prevent="submit" class="space-y-6">

                        <!-- Album Title -->
                        <div>
                            <InputLabel for="title" value="Album Title" class="text-foreground" />
                            <input
                                id="title"
                                type="text"
                                class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4"
                                v-model="form.title"
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>

                        <!-- Description (optional) -->
                        <div>
                            <InputLabel for="description" value="Description (optional)" class="text-foreground" />
                            <textarea
                                id="description"
                                class="mt-2 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary p-4"
                                v-model="form.description"
                                rows="3"
                                placeholder="A short description of this album…"
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <!-- Location -->
                        <div>
                            <InputLabel for="location" value="Location" class="text-foreground" />
                            <select
                                id="location"
                                class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 appearance-none"
                                v-model="form.location"
                                required
                            >
                                <option value="" disabled>Select Location…</option>
                                <option value="Ahmedabad">Ahmedabad</option>
                                <option value="Rajkot">Rajkot</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.location" />
                        </div>

                        <!-- Info banner: albums are always public -->
                        <div class="flex items-center gap-3 bg-primary/5 border border-primary/20 rounded-lg px-4 py-3">
                            <svg class="w-5 h-5 text-primary shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-sm text-primary font-medium">
                                All albums are <span class="font-bold">public</span> — visible to everyone in your team.
                            </p>
                        </div>

                        <!-- Submit -->
                        <div class="flex items-center justify-end pt-4 border-t border-border">
                            <button
                                type="submit"
                                class="flex items-center gap-2 h-11 px-8 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-2px] transition-all whitespace-nowrap"
                                :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                                :disabled="form.processing"
                            >
                                <svg v-if="form.processing" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                </svg>
                                Update Album
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
