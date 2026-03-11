<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    availableParents: Array,
    defaultParentId: Number,
});

const form = useForm({
    title: '',
    description: '',
    type: 'festival',
    event_date: '',
    is_public: false,
    parent_id: props.defaultParentId || null,
    location: '',
});

const submit = () => {
    form.post(route('albums.store'));
};
</script>

<template>
    <Head title="Create Album" />

    <AuthenticatedLayout>
        <div class="py-12 animate-fade-in text-foreground max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="font-heading font-bold text-3xl">Create Album</h1>
                    <p class="text-sm text-muted-foreground mt-1">Add a new collection to your gallery</p>
                </div>
                
                <Link :href="route('albums.index')" class="flex items-center gap-2 h-11 px-6 rounded-pill bg-bg-elevated border border-border text-foreground font-bold text-sm shadow-sm hover:translate-y-[-2px] transition-all whitespace-nowrap">
                    Back to Albums
                </Link>
            </div>

            <div class="max-w-3xl">
                <div class="bg-bg-card border border-border sm:rounded-2xl p-6 sm:p-8 shadow-sm">
                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="title" value="Album Title" class="text-foreground" />
                            <input id="title" type="text" class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4" v-model="form.title" required autofocus />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>

                        <div>
                            <InputLabel for="description" value="Description" class="text-foreground" />
                            <textarea id="description" class="mt-2 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary p-4" v-model="form.description" rows="3"></textarea>
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <div>
                            <InputLabel for="parent_album" value="Parent Album (Optional)" class="text-foreground" />
                            <select id="parent_album" class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 appearance-none" v-model="form.parent_id">
                                <option :value="null">None (Root Level)</option>
                                <option v-for="album in availableParents" :key="album.id" :value="album.id">
                                    {{ album.title }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.parent_id" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <InputLabel for="type" value="Type" class="text-foreground" />
                                <select id="type" class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 appearance-none" v-model="form.type">
                                    <option value="festival">Festival</option>
                                    <option value="event">Event</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="event_date" value="Event Date" class="text-foreground" />
                                <input id="event_date" type="date" class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 color-scheme-dark" v-model="form.event_date" />
                            </div>
                        </div>

                        <div>
                            <InputLabel for="location" value="Location" class="text-foreground" />
                            <select id="location" class="mt-2 h-11 block w-full bg-bg-input border-border text-foreground rounded-lg shadow-sm focus:border-primary focus:ring-1 focus:ring-primary px-4 appearance-none" v-model="form.location" required>
                                <option value="" disabled>Select Location...</option>
                                <option value="Ahmedabad">Ahmedabad</option>
                                <option value="Rajkot">Rajkot</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.location" />
                        </div>

                        <div class="flex items-center bg-bg-elevated p-4 rounded-lg border border-border/50 transition-colors hover:border-primary/50 cursor-pointer" @click="form.is_public = !form.is_public">
                            <input id="is_public" type="checkbox" class="rounded w-5 h-5 bg-bg-input border-border text-primary shadow-sm focus:ring-primary" v-model="form.is_public" @click.stop />
                            <label for="is_public" class="ml-3 text-sm font-medium text-foreground cursor-pointer" @click.stop>Make this album public</label>
                            <span class="ml-auto text-xs text-muted-foreground mr-2 hidden sm:block">Anyone can view this</span>
                        </div>

                        <div class="flex items-center justify-end pt-4 border-t border-border">
                            <button type="submit" class="flex items-center gap-2 h-11 px-8 rounded-pill bg-gradient-to-r from-primary to-accent-hover text-primary-foreground font-bold text-sm shadow-lg hover:translate-y-[-2px] transition-all whitespace-nowrap" :class="{ 'opacity-50 cursor-not-allowed': form.processing }" :disabled="form.processing">
                                Create Album
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
