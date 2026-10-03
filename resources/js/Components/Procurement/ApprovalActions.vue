<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Approve / send back / reject / withdraw buttons for a document in the approval engine.
 * `approval` is the pending request summary ({ id, can_act, can_cancel, ... }) or null.
 */
const props = defineProps({
    approval: { type: Object, default: null },
    noun: { type: String, default: 'document' },
});

const action = ref(null);
const form = useForm({ comments: '' });
const ACTIONS = {
    approve: { title: `Approve ${props.noun}`, route: 'approvals.approve', button: 'Approve', variant: 'primary', required: false },
    sendBack: { title: 'Send back for changes', route: 'approvals.send-back', button: 'Send back', variant: 'secondary', required: true },
    reject: { title: `Reject ${props.noun}`, route: 'approvals.reject', button: 'Reject', variant: 'danger', required: true },
    cancel: { title: 'Withdraw approval request', route: 'approvals.cancel', button: 'Withdraw', variant: 'danger', required: false },
};

function open(name) {
    form.reset();
    form.clearErrors();
    action.value = name;
}

function submit() {
    form.post(route(ACTIONS[action.value].route, props.approval.id), {
        preserveScroll: true,
        onSuccess: () => (action.value = null),
    });
}
</script>

<template>
    <template v-if="approval">
        <template v-if="approval.can_act">
            <AppButton size="sm" @click="open('approve')">Approve</AppButton>
            <AppButton size="sm" variant="secondary" @click="open('sendBack')">Send back</AppButton>
            <AppButton size="sm" variant="ghost" class="text-red-600" @click="open('reject')">Reject</AppButton>
        </template>
        <AppButton v-if="approval.can_cancel" size="sm" variant="secondary" @click="open('cancel')">Withdraw</AppButton>

        <AppModal :show="!!action" :title="action ? ACTIONS[action].title : ''" @close="action = null">
            <FormInput
                v-model="form.comments"
                :label="action && ACTIONS[action].required ? 'Reason' : 'Comments (optional)'"
                multiline
                :rows="3"
                :required="action ? ACTIONS[action].required : false"
                :error="form.errors.comments || form.errors.approval"
            />
            <template #footer>
                <AppButton variant="secondary" @click="action = null">Cancel</AppButton>
                <AppButton :variant="action ? ACTIONS[action].variant : 'primary'" :loading="form.processing" @click="submit">
                    {{ action ? ACTIONS[action].button : '' }}
                </AppButton>
            </template>
        </AppModal>
    </template>
</template>
