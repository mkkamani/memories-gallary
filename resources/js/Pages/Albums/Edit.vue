<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    album: Object,
});

const form = useForm({
    title: props.album.title,
    description: props.album.description,
    type: props.album.type,
    event_date: props.album.event_date,
    is_public: props.album.is_public,
});

const submit = () => {
    form.put(route('albums.update', props.album.id));
};
</script>

<template>
    <Head title="Edit Album" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Edit Album</h2>
        </template>

        <div class="py-12">
            <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-brand-dark overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="title" value="Album Title" />
                            <TextInput id="title" type="text" class="mt-1 block w-full bg-brand-gray border-gray-700 text-white" v-model="form.title" required autofocus />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>

                        <div>
                            <InputLabel for="description" value="Description" />
                            <textarea id="description" class="mt-1 block w-full bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red" v-model="form.description" rows="3"></textarea>
                            <InputError class="mt-2" :message="form.errors.description" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="type" value="Type" />
                                <select id="type" class="mt-1 block w-full bg-brand-gray border-gray-700 text-white rounded-md shadow-sm focus:border-brand-red focus:ring-brand-red" v-model="form.type">
                                    <option value="festival">Festival</option>
                                    <option value="event">Event</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="event_date" value="Event Date" />
                                <TextInput id="event_date" type="date" class="mt-1 block w-full bg-brand-gray border-gray-700 text-white" v-model="form.event_date" />
                            </div>
                        </div>

                        <div class="flex items-center">
                            <input id="is_public" type="checkbox" class="rounded bg-brand-gray border-gray-700 text-brand-red shadow-sm focus:ring-brand-red" v-model="form.is_public" />
                            <label for="is_public" class="ml-2 text-sm text-gray-400">Make this album public</label>
                        </div>

                        <div class="flex items-center justify-end">
                            <PrimaryButton class="ml-4 bg-brand-red hover:bg-brand-red-hover" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                Update Album
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
