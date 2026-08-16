const o="towerdns-color-mode",e=localStorage.getItem(o),t=window.matchMedia("(prefers-color-scheme: dark)").matches,r=e==="dark"||e!=="light"&&t;document.documentElement.classList.toggle("dark",r);
