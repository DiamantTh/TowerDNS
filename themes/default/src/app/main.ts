import { mount } from 'svelte';
import App from './App.svelte';
import '../styles/app.css';

const target = document.getElementById('towerdns-app');
const source = document.getElementById('towerdns-page');

if (target && source?.textContent) {
    mount(App, { target, props: { boot: JSON.parse(source.textContent) } });
}
