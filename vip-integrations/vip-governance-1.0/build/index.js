!function(){"use strict";var e=window.wp.blockEditor,o=window.wp.data,n=window.wp.hooks,t=window.wp.i18n,i=window.wp.notices,r=window.wp.element,c=window.wp.components,l=window.wp.compose;function s(e,o={},n=!1){const t=["allowedBlocks"];for(const[r,c]of Object.entries(e))if(!t.includes(r))if(r.includes("/"))Object.entries(e).forEach((([e,n])=>{t.includes(e)||s(n,o,e)}));else if(!1!==n){var i;const e=u(c,`${r}.`);o[n]={...null!==(i=o[n])&&void 0!==i?i:{},...e}}return o}function a(e,o,n,t={depth:0,value:void 0},i=1){const[r,...c]=e,l=n[r];if(0===c.length){const e=d(l,o);return void 0!==e&&i>=t.depth&&(t.depth=i,t.value=e),t}return void 0!==l&&(t=a(c,o,l,t,i+1)),a(c,o,n,t,i)}function d(e,o,n=void 0){const t=Array.isArray(o)?o:o.replace(/(\[(\d)\])/g,".$2").replace(/^\./,"").split(".");if(!t.length||void 0===t[0])return e;const i=t[0];return"object"==typeof e&&null!==e&&i in e&&void 0!==e[i]?d(e[i],t.slice(1),n):n}function u(e,o=""){const n={};return Object.entries(e).forEach((([e,t])=>{"object"==typeof t&&Boolean(t)&&!Array.isArray(t)?(n[`${o}${e}`]=!0,Object.assign(n,u(t,`${o}${e}.`))):n[`${o}${e}`]=!0})),n}const p={"core/list":["core/list-item"],"core/columns":["core/column"],"core/page-list":["core/page-list-item"],"core/navigation":["core/navigation-link","core/navigation-submenu"],"core/navigation-link":["core/navigation-link","core/navigation-submenu","core/page-list"],"core/quote":["core/paragraph"],"core/media-text":["core/paragraph"],"core/social-links":["core/social-link"],"core/comments-pagination":["core/comments-pagination-previous","core/comments-pagination-numbers","core/comments-pagination-next"]};function g(e,o,t){const i=(0,n.applyFilters)("vip_governance__is_block_allowed_in_hierarchy",!0,e,o,t)||0===o.length?[...t.allowedBlocks]:[];if(o.length>0){if(p[o[0]]&&p[o[0]].includes(e))return!0;if(t.blockSettings){const e=a(o.reverse(),"allowedBlocks",t.blockSettings);e&&e.value&&i.push(...e.value)}}return function(e,o){return o.some((o=>function(e,o){return o.includes("*")?e.match(new RegExp(o.replace("*",".*"))):o===e}(e,o)))}(e,i)}const m={};!function(){if(VIP_GOVERNANCE.error)return void(0,o.dispatch)(i.store).createErrorNotice(VIP_GOVERNANCE.error,{id:"wpcomvip-governance-error",isDismissible:!0,actions:[{label:(0,t.__)("Open governance settings"),url:VIP_GOVERNANCE.urlSettingsPage}]});const d=VIP_GOVERNANCE.governanceRules;(0,n.addFilter)("blockEditor.__unstableCanInsertBlockType","wpcomvip-governance/block-insertion",((t,i,r,{getBlock:c})=>{if(!1===t)return t;let l=[];if(r){const{getBlockParents:n,getBlockName:t}=(0,o.select)(e.store),i=c(r),s=n(r,!0);l=[i.clientId,...s].map((e=>t(e)))}const s=g(i.name,l,d);return(0,n.applyFilters)("vip_governance__is_block_allowed_for_insertion",s,i.name,l,d)}));const u=VIP_GOVERNANCE.nestedSettings,p=s(u);(0,n.addFilter)("blockEditor.useSetting.before","wpcomvip-governance/nested-block-settings",((n,t,i,r)=>{if(void 0===p[r]||!0!==p[r][t])return n;const c=[i,...(0,o.select)(e.store).getBlockParents(i,!0)].map((n=>(0,o.select)(e.store).getBlockName(n))).reverse();return({value:n}=a(c,t,u)),n&&n.theme?n.theme:n})),d?.allowedBlocks&&function(t){const i=(0,l.createHigherOrderComponent)((i=>l=>{const{name:s,clientId:a}=l,{getBlockParents:d,getBlockName:u}=(0,o.select)(e.store),p=d(a,!0),v=p.some((e=>function(e){return e in m}(e)));if(v)return(0,r.createElement)(i,{...l});const w=p.map((e=>u(e)));let f=g(s,w,t);if(f=(0,n.applyFilters)("vip_governance__is_block_allowed_for_editing",f,s,w,t),f)return(0,r.createElement)(i,{...l});if(wp?.blockEditor?.useBlockEditingMode){const{useBlockEditingMode:e}=wp.blockEditor;e("disabled")}return function(e){m[e]=!0}(a),(0,r.createElement)(r.Fragment,null,(0,r.createElement)(c.Disabled,null,(0,r.createElement)("div",{style:{opacity:.6,"background-color":"#eee",border:"2px dashed #999"}},(0,r.createElement)(i,{...l}))))}),"withDisabledBlocks");(0,n.addFilter)("editor.BlockEdit","wpcomvip-governance/with-disabled-blocks",i)}(d)}()}();