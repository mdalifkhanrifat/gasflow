import { computed, ref } from 'vue';

const workspaces = [
    {
        name: 'Northstar Gas Services',
        buildings: ['Lake View Tower', 'Green Residency'],
    },
    { name: 'Green View Management', buildings: ['Green Residency'] },
    { name: 'Metro Utility Ltd.', buildings: ['Metro Heights'] },
] as const;

const workspace = ref<string>(workspaces[0].name);
const building = ref('');
const buildings = computed(
    () =>
        workspaces.find((option) => option.name === workspace.value)
            ?.buildings ?? [],
);

function selectWorkspace(value: string): void {
    workspace.value = value;
    building.value = '';
}

export function usePreviewWorkspace() {
    return { workspace, workspaces, building, buildings, selectWorkspace };
}
