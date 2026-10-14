const e=()=>globalThis.document?.documentElement?.dataset.units!=="0",a=t=>e()?String(t??""):"",s=(t,n)=>`${Number(t)}${a(n)?` ${a(n)}`:""}`;export{e as a,s as q,a as u};
