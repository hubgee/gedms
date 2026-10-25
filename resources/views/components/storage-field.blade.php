@props(['field', 'driverKey', 'config'])

@php
    $key = (string) $field['key'];
    $type = (string) ($field['type'] ?? 'text');
    $name = 'settings['.$driverKey.']['.$key.']';
    $id = 'setting-'.$driverKey.'-'.$key;
    $stored = isset($config[$key]) && $config[$key] !== '' && $config[$key] !== null;
    $value = $stored ? $config[$key] : ($field['default'] ?? '');
    $secret = (bool) ($field['secret'] ?? false);

    // Re-populate ordinary fields after a validation error. Secrets are never
    // echoed back, even from old input: a blank secret keeps the stored value.
    if (session()->hasOldInput()) {
        $value = $type === 'checkbox'
            ? (bool) old('settings.'.$driverKey.'.'.$key)
            : (old('settings.'.$driverKey.'.'.$key) ?? '');
    }

    $placeholder = $stored && $secret
        ? 'Stored — leave blank to keep'
        : (string) ($field['placeholder'] ?? '');
@endphp

<div class="{{ $type === 'checkbox' ? 'md:col-span-2' : '' }}">
    @if($type === 'checkbox')
        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox"
                   id="{{ $id }}"
                   name="{{ $name }}"
                   value="1"
                   {{ $value ? 'checked' : '' }}
                   class="rounded border-slate-300">
            {{ $field['label'] }}
        </label>
    @else
        <label for="{{ $id }}" class="block text-sm text-slate-600 mb-1">{{ $field['label'] }}</label>

        @if($type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                @foreach(($field['options'] ?? []) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}" {{ (string) $value === (string) $optionValue ? 'selected' : '' }}>{{ $optionLabel }}</option>
                @endforeach
            </select>
        @else
            <input type="{{ $secret ? 'password' : ($type === 'url' ? 'url' : $type) }}"
                   id="{{ $id }}"
                   name="{{ $name }}"
                   value="{{ $secret ? '' : $value }}"
                   placeholder="{{ $placeholder }}"
                   @if($secret) autocomplete="new-password" @endif
                   @if($type === 'number') min="0" @endif
                   class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
        @endif
    @endif

    @if(! empty($field['help']))
        <p class="text-xs text-slate-400 mt-1">{{ $field['help'] }}</p>
    @endif
</div>
