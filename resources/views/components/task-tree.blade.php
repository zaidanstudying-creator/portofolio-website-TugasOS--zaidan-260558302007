@props(['task'])

<div wire:key="task-tree-{{ $task->id }}" class="space-y-2 border-s-2 border-black ps-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
            <p class="font-black"><button type="button" wire:click="editTask({{ $task->id }})" class="text-left underline decoration-2 underline-offset-2">{{ $task->title }}</button></p>
            <p class="mt-1 text-xs text-[#555]">{{ str_replace('_', ' ', $task->status) }} · {{ $task->priority }} · bobot {{ $task->weight }}@if($task->due_at) · tenggat {{ $task->due_at->format('d M Y, H:i') }}@endif</p>
            <div class="mt-2 flex items-center gap-2"><progress value="{{ $task->computedProgress() }}" max="100" aria-label="Progres {{ $task->title }}" class="h-2 flex-1 accent-[#facc15]">{{ $task->computedProgress() }}%</progress><span class="text-xs font-black">{{ $task->computedProgress() }}%</span></div>
        </div>
        <div class="flex gap-1">
            @if($task->status !== 'done')
                <button type="button" wire:click="updateStatus({{ $task->id }}, '{{ $task->status === 'todo' ? 'in_progress' : 'done' }}')" class="border border-black bg-[#b8edce] px-2 py-1 text-xs font-bold">{{ $task->status === 'todo' ? 'Mulai' : 'Selesai' }}</button>
            @else
                <button type="button" wire:click="updateStatus({{ $task->id }}, 'todo')" class="border border-black px-2 py-1 text-xs font-bold">Buka kembali</button>
            @endif
            <button type="button" wire:click="deleteTask({{ $task->id }})" wire:confirm="Hapus tugas ini beserta subtask-nya?" aria-label="Hapus {{ $task->title }}" class="border border-black px-2 py-1 text-xs font-bold hover:bg-[#ff8c78]">Hapus</button>
        </div>
    </div>

    @if($task->items->isNotEmpty())
        <p class="text-[10px] font-black uppercase tracking-wide">Checklist @if($task->children->isNotEmpty())<span class="font-normal normal-case">— progres dihitung dari subtask agar tidak terhitung ganda</span>@endif</p>
        <ul class="divide-y divide-black border-y border-black">
            @foreach($task->items as $item)
                <li wire:key="task-tree-item-{{ $item->id }}" class="flex items-start gap-2 py-1.5">
                    <input id="task-tree-item-{{ $item->id }}" type="checkbox" @checked($item->is_completed) wire:click="toggleItem({{ $task->id }}, {{ $item->id }})" class="mt-1 size-4 accent-black">
                    <label for="task-tree-item-{{ $item->id }}" class="flex-1 text-sm {{ $item->is_completed ? 'text-[#777] line-through' : '' }}">{{ $item->title }}</label>
                    <button type="button" wire:click="deleteItem({{ $task->id }}, {{ $item->id }})" wire:confirm="Hapus checklist ini? Progres tugas akan dihitung ulang." aria-label="Hapus checklist {{ $item->title }}" class="text-xs font-bold underline">Hapus</button>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addItem({{ $task->id }})" class="flex min-w-0 gap-2">
        <label class="sr-only" for="new-tree-item-{{ $task->id }}">Checklist baru untuk {{ $task->title }}</label>
        <input id="new-tree-item-{{ $task->id }}" type="text" wire:model="newItemTitle" placeholder="Tambah checklist" class="min-w-0 flex-1 border border-black px-2 py-1 text-xs">
        <button class="border border-black px-2 py-1 text-xs font-bold hover:bg-[#b8edff]">Tambah</button>
    </form>
    @error('newItemTitle')<p class="text-xs font-semibold text-red-700">{{ $message }}</p>@enderror

    @foreach($task->children as $child)
        <x-task-tree :task="$child" />
    @endforeach
</div>
