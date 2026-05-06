<template>
    <div>
        <div v-if="!config.configured" class="card p-4">
            <p class="text-gray-700 dark:text-gray-300">
                IndexNow is not configured. Add <code>INDEXNOW_KEY</code> to your
                <code>.env</code> file and host a matching <code>{key}.txt</code> file
                at the root of your production URL.
            </p>
        </div>

        <div v-else>
            <div class="mb-4">
                <ui-select
                    v-model="collectionFilter"
                    :options="collectionsOptions"
                    placeholder="All collections"
                    clearable
                    size="sm"
                />
            </div>

            <ui-listing
                :url="config.entries_url"
                :columns="columns"
                sort-column="updated_at"
                sort-direction="desc"
                :additional-parameters="additionalParameters"
                :allow-bulk-actions="false"
                :allow-presets="false"
                :allow-customizing-columns="false"
                v-model:selections="selectedIds"
                @request-completed="onRequestCompleted"
            >
                <template #cell-title="{ row }">
                    <a :href="row.edit_url" class="text-blue-600 dark:text-blue-400 hover:underline">{{ row.title }}</a>
                </template>
                <template #cell-collection="{ row }">
                    <ui-badge :text="row.collection" size="sm" />
                </template>
                <template #cell-status="{ row }">
                    <ui-badge :text="row.status" :color="statusColor(row.status)" pill size="sm" />
                </template>
                <template #cell-updated_at="{ row }">
                    <span class="text-sm whitespace-nowrap">{{ row.updated_at || '—' }}</span>
                </template>
                <template #cell-last_submitted="{ row }">
                    <span class="text-sm whitespace-nowrap">{{ row.last_submitted || '—' }}</span>
                </template>
            </ui-listing>

            <div class="mt-4 flex items-center gap-3 flex-wrap">
                <ui-button
                    variant="primary"
                    :disabled="!selectedIds.length || submitting"
                    @click="submitSelected"
                >Submit{{ selectedIds.length ? ` (${selectedIds.length})` : '' }} to IndexNow</ui-button>

                <span class="text-sm text-gray-500 dark:text-gray-400">{{ selectedIds.length }} selected</span>

                <div class="ml-auto flex gap-2">
                    <ui-button size="sm" @click="selectByStatus('never')">Select unsubmitted</ui-button>
                    <ui-button size="sm" @click="selectByStatus('modified')">Select modified</ui-button>
                    <ui-button v-if="selectedIds.length" size="sm" variant="ghost" @click="selectedIds = []">Clear</ui-button>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'IndexNowUtility',

    props: {
        config: { type: Object, required: true },
    },

    data() {
        return {
            columns: [
                { field: 'title', label: 'Title', sortable: true },
                { field: 'collection', label: 'Collection', sortable: true },
                { field: 'status', label: 'Status', sortable: true },
                { field: 'updated_at', label: 'Modified', sortable: true },
                { field: 'last_submitted', label: 'Submitted', sortable: true },
            ],
            collectionFilter: null,
            collections: this.config.collections || [],
            selectedIds: [],
            entryUrlMap: {},
            submitting: false,
        }
    },

    computed: {
        additionalParameters() {
            return this.collectionFilter ? { collection: this.collectionFilter } : {}
        },

        collectionsOptions() {
            return this.collections.map(c => ({ value: c, label: c }))
        },
    },

    methods: {
        onRequestCompleted({ items }) {
            items.forEach(item => {
                this.entryUrlMap[item.id] = item.url
            })
        },

        statusColor(status) {
            const colors = { never: 'default', modified: 'amber', submitted: 'green' }
            return colors[status] ?? 'default'
        },

        async selectByStatus(filter) {
            try {
                const resp = await fetch(`${this.config.select_url}?filter=${filter}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                })
                if (!resp.ok) throw new Error()
                const data = await resp.json()
                const ids = []
                data.entries.forEach(e => {
                    this.entryUrlMap[e.id] = e.url
                    ids.push(e.id)
                })
                this.selectedIds = ids
            } catch {
                Statamic.$toast.error('Failed to load selection.')
            }
        },

        async submitSelected() {
            if (!this.selectedIds.length) return
            this.submitting = true
            try {
                const payload = {
                    urls: this.selectedIds
                        .filter(id => this.entryUrlMap[id])
                        .map(id => ({ url: this.entryUrlMap[id], entry_id: id })),
                }
                const resp = await fetch(this.config.submit_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.config.csrf_token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                })
                if (!resp.ok) {
                    let msg = 'Submission failed.'
                    try { msg = (await resp.json()).message || msg } catch {}
                    Statamic.$toast.error(msg)
                } else {
                    const data = await resp.json()
                    Statamic.$toast.success(data.message)
                    this.selectedIds = []
                }
            } catch {
                Statamic.$toast.error('An unexpected error occurred.')
            } finally {
                this.submitting = false
            }
        },
    },
}
</script>
