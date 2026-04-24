function a(r){const e=r.get("etag");if(!e)return"";let t=e.trim();return t.startsWith("W/")&&(t=t.slice(2)),t=t.replace(/^"|"$/g,""),t=t.replace(/[-;](?:gzip|br|deflate)$/i,""),t}export{a as e};
