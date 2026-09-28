function r(e,t=document.body){const o=typeof t=="string"?document.querySelector(t):t;return o&&o.appendChild(e),{destroy(){e.parentNode&&e.parentNode.removeChild(e)}}}export{r as p};
