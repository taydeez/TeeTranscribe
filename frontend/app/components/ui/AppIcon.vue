<script setup lang="ts">
import { LoaderCircle, AudioLines, FileText, Languages, Mic, Upload, Folder, House, Settings, CreditCard, LogOut, Plus, ChevronRight, ArrowRight, ArrowLeft, Menu, X, Moon, Sun, Play, Pause, Volume2, Check, Clock, AlertCircle, Search, Download, Video, Users, Gauge } from '@lucide/vue'
const icons = { loader: LoaderCircle, audio: AudioLines, transcript: FileText, translate: Languages, mic: Mic, upload: Upload, folder: Folder, home: House, settings: Settings, billing: CreditCard, logout: LogOut, plus: Plus, right: ChevronRight, arrow: ArrowRight, back: ArrowLeft, menu: Menu, close: X, moon: Moon, sun: Sun, play: Play, pause: Pause, volume: Volume2, check: Check, clock: Clock, error: AlertCircle, search: Search, download: Download, video: Video, users: Users, usage: Gauge }
withDefaults(defineProps<{ name: keyof typeof icons; size?: number }>(), { size: 20 })
</script>
<template><component :is="icons[name]" :size="size" :stroke-width="1.7" aria-hidden="true" /></template>
