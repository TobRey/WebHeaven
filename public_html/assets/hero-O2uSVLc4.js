import{W as ae,A as se,S as q,F as re,P as le,O as ce,a as ue,D as de,b as Y,M as K,C as b,G as B,c as me,d as Q,V as M,e as z,E as $,f as j,L as ve,g as Z,B as R,h as J,i as fe,j as he,k as D,l as pe,m as ge,n as we}from"./three-NAXOs3fP.js";import{l as A,c as N}from"./main-BDSM8vaz.js";const k={dark:{indigo:4928456,indigoLight:9143807,teal:1558728,tealLight:3137756,background:394767,fog:657951},light:{indigo:2825118,indigoLight:7101671,teal:1223081,tealLight:1558728,background:16185339,fog:16777215}};function xe(i,t,e){const o=new j;return o.moveTo(i.x-e,i.y),o.lineTo(i.x+e,i.y),o.lineTo(t.x+e,t.y),o.lineTo(t.x-e,t.y),o.closePath(),o}function ye(i){const t=[new M(-.92,.62),new M(-.45,-.62),new M(0,.3),new M(.45,-.62),new M(.92,.62)],e={depth:.26,bevelEnabled:!0,bevelThickness:.035,bevelSize:.028,bevelSegments:3,curveSegments:2},o=new B;for(let u=0;u<t.length-1;u++){const s=xe(t[u],t[u+1],.165),h=new $(s,e);h.center();const r=new z(h,i),l=(t[u].x+t[u+1].x)/2,a=(t[u].y+t[u+1].y)/2;r.position.set(l,a,0),o.add(r)}return o}function Me(i){const t=new j;t.moveTo(-.3,.06),t.lineTo(-.11,-.16),t.lineTo(.34,.44),t.lineTo(.2,.56),t.lineTo(-.12,.13),t.lineTo(-.2,.22),t.closePath();const e=new $(t,{depth:.14,bevelEnabled:!0,bevelThickness:.02,bevelSize:.018,bevelSegments:2,curveSegments:2});return e.center(),new z(e,i)}function be(i){const t=new B,e=1,o=new ve({color:i.teal,transparent:!0,opacity:.42});for(let l=0;l<6;l++){const a=new Z(0,0,e,e,0,Math.PI*2,!1,0),m=new R().setFromPoints(a.getPoints(72)),v=new J(m,o);v.rotation.y=l/6*Math.PI,t.add(v)}for(let l=1;l<5;l++){const a=l/5,m=Math.cos(a*Math.PI)*e,v=Math.sin(a*Math.PI)*e,P=new Z(0,0,v,v,0,Math.PI*2,!1,0),C=new R().setFromPoints(P.getPoints(64)),y=new J(C,o);y.rotation.x=Math.PI/2,y.position.y=m,t.add(y)}const u=new fe(.042,12,12),s=new he({color:i.tealLight}),h=[[.62,.42,.66],[-.48,.7,.53],[.3,-.3,.9],[-.72,-.35,.6],[.88,-.12,.46],[-.15,.92,-.36]],r=new B;for(const[l,a,m]of h){const v=new z(u,s);v.position.set(l,a,m).normalize().multiplyScalar(e),r.add(v)}return t.add(r),t.userData.nodes=r,t}function Pe(i,t){const e=new Float32Array(i*3),o=new Float32Array(i*3),u=new Float32Array(i);for(let r=0;r<i;r++){const l=3.4+Math.random()*7.5,a=Math.random()*Math.PI*2,m=Math.acos(2*Math.random()-1);e[r*3]=l*Math.sin(m)*Math.cos(a),e[r*3+1]=l*Math.sin(m)*Math.sin(a)*.6,e[r*3+2]=l*Math.cos(m),o[r*3]=(Math.random()-.5)*2,o[r*3+1]=(Math.random()-.5)*2,o[r*3+2]=(Math.random()-.5)*2,u[r]=Math.random()*.028+.008}const s=new R;s.setAttribute("position",new D(e,3)),s.setAttribute("aScatter",new D(o,3)),s.setAttribute("aSize",new D(u,1));const h=new Q({transparent:!0,depthWrite:!1,blending:pe,uniforms:{uTime:{value:0},uDisperse:{value:0},uColorA:{value:new b(t.indigoLight)},uColorB:{value:new b(t.tealLight)},uPixelRatio:{value:1}},vertexShader:`
      attribute vec3 aScatter;
      attribute float aSize;
      uniform float uTime;
      uniform float uDisperse;
      uniform float uPixelRatio;
      varying float vMix;
      varying float vFade;

      void main() {
        vec3 pos = position;

        // Ruhiges Treiben
        pos.x += sin(uTime * 0.22 + position.z * 0.5) * 0.16;
        pos.y += cos(uTime * 0.18 + position.x * 0.5) * 0.16;

        // Beim Scrollen auseinanderstieben
        pos += aScatter * uDisperse * 5.5;

        vec4 mvPosition = modelViewMatrix * vec4(pos, 1.0);
        gl_Position = projectionMatrix * mvPosition;

        // Weiter entfernt heisst kleiner
        gl_PointSize = aSize * 620.0 * uPixelRatio / max(-mvPosition.z, 0.001);

        vMix = clamp((position.x + 6.0) / 12.0, 0.0, 1.0);
        vFade = 1.0 - uDisperse * 0.85;
      }
    `,fragmentShader:`
      uniform vec3 uColorA;
      uniform vec3 uColorB;
      varying float vMix;
      varying float vFade;

      void main() {
        // Runder, weich auslaufender Punkt
        float d = length(gl_PointCoord - vec2(0.5));
        if (d > 0.5) discard;
        float alpha = smoothstep(0.5, 0.06, d) * vFade;

        gl_FragColor = vec4(mix(uColorA, uColorB, vMix), alpha * 0.85);
      }
    `});return new ge(s,h)}function Ce(i){const t=new me(2,2),e=new Q({depthWrite:!1,depthTest:!1,uniforms:{uTime:{value:0},uPointer:{value:new M(.5,.5)},uAspect:{value:1},uColorA:{value:new b(i.indigo)},uColorB:{value:new b(i.teal)},uColorBg:{value:new b(i.background)},uIntensity:{value:1}},vertexShader:`
      varying vec2 vUv;
      void main() {
        vUv = uv;
        gl_Position = vec4(position.xy, 0.0, 1.0);
      }
    `,fragmentShader:`
      precision highp float;

      varying vec2 vUv;
      uniform float uTime;
      uniform vec2 uPointer;
      uniform float uAspect;
      uniform vec3 uColorA;
      uniform vec3 uColorB;
      uniform vec3 uColorBg;
      uniform float uIntensity;

      // Einfaches Rauschen – die Grundlage für weiche, unregelmässige Flecken
      vec2 hash(vec2 p) {
        p = vec2(dot(p, vec2(127.1, 311.7)), dot(p, vec2(269.5, 183.3)));
        return -1.0 + 2.0 * fract(sin(p) * 43758.5453123);
      }

      float noise(vec2 p) {
        vec2 i = floor(p);
        vec2 f = fract(p);
        vec2 u = f * f * (3.0 - 2.0 * f);
        return mix(
          mix(dot(hash(i + vec2(0.0, 0.0)), f - vec2(0.0, 0.0)),
              dot(hash(i + vec2(1.0, 0.0)), f - vec2(1.0, 0.0)), u.x),
          mix(dot(hash(i + vec2(0.0, 1.0)), f - vec2(0.0, 1.0)),
              dot(hash(i + vec2(1.0, 1.0)), f - vec2(1.0, 1.0)), u.x),
          u.y
        );
      }

      float fbm(vec2 p) {
        float value = 0.0;
        float amplitude = 0.5;
        for (int i = 0; i < 4; i++) {
          value += amplitude * noise(p);
          p *= 2.03;
          amplitude *= 0.5;
        }
        return value;
      }

      void main() {
        vec2 uv = vUv;
        vec2 p = (uv - 0.5) * vec2(uAspect, 1.0);

        float t = uTime * 0.045;
        float n = fbm(p * 1.7 + vec2(t, -t * 0.7));
        float n2 = fbm(p * 2.6 - vec2(t * 0.5, t * 0.9) + n);

        // Zwei weiche Lichtquellen, eine folgt der Maus
        vec2 pointer = (uPointer - 0.5) * vec2(uAspect, 1.0);
        float glowA = 1.0 - smoothstep(0.0, 1.15, length(p - pointer * 0.55) - n * 0.28);
        float glowB = 1.0 - smoothstep(0.0, 1.35, length(p + vec2(0.55, 0.30)) - n2 * 0.32);

        vec3 color = uColorBg;
        color = mix(color, uColorA, clamp(glowA * 0.55 * uIntensity, 0.0, 1.0));
        color = mix(color, uColorB, clamp(glowB * 0.38 * uIntensity, 0.0, 1.0));

        // Feine Körnung gegen Streifenbildung im Verlauf
        float grain = fract(sin(dot(uv, vec2(12.9898, 78.233))) * 43758.5453);
        color += (grain - 0.5) * 0.016;

        gl_FragColor = vec4(color, 1.0);
      }
    `}),o=new z(t,e);return o.frustumCulled=!1,o.renderOrder=-1,o}function Ae(i){if(!i)return null;const t=document.documentElement.getAttribute("data-theme")==="light";let e=t?k.light:k.dark;const o=new ae({antialias:!0,alpha:!1,powerPreference:"high-performance"}),u=Math.min(window.devicePixelRatio||1,1.75);o.setPixelRatio(u),o.setSize(i.clientWidth,i.clientHeight),o.setClearColor(e.background,1),o.toneMapping=se,o.toneMappingExposure=1.15,i.append(o.domElement);const s=new q;s.fog=new re(e.fog,7,18);const h=new le(42,i.clientWidth/i.clientHeight,.1,100);h.position.set(0,0,6.2);const r=new q,l=new ce(-1,1,1,-1,0,1),a=Ce(e);r.add(a);const m=new ue(16777215,t?1.1:.55);s.add(m);const v=new de(16777215,t?2:2.6);v.position.set(3.2,4,5),s.add(v);const P=new Y(e.tealLight,34,16,2);P.position.set(3.6,-1.2,2.4),s.add(P);const C=new Y(e.indigoLight,26,16,2);C.position.set(-3.8,2,1.8),s.add(C);const y=new K({color:e.indigo,metalness:.72,roughness:.22}),T=new K({color:e.teal,metalness:.55,roughness:.18,emissive:new b(e.teal),emissiveIntensity:t?.08:.28}),c=new B;s.add(c);function ee(){const n=i.clientWidth>=1e3,d=i.clientWidth>=1400;c.position.x=n?d?2.45:1.95:0,c.scale.setScalar(n?.82:.62),c.userData.baseX=c.position.x}const L=ye(y);L.scale.setScalar(1.42),L.position.set(-.55,.05,.35),c.add(L);const S=Me(T);S.scale.setScalar(1.3),S.position.set(-.34,.3,.62),c.add(S);const g=be(e);g.scale.setScalar(1.32),g.position.set(.8,-.05,-.7),c.add(g);const w=Pe(1400,e);w.material.uniforms.uPixelRatio.value=u,s.add(w);const f={x:.5,y:.5,tx:.5,ty:.5};let x=0,G=!0,E=!0,W=0;const _=new we,V=n=>{f.tx=n.clientX/window.innerWidth,f.ty=n.clientY/window.innerHeight},F=()=>{x=N(window.scrollY/Math.max(window.innerHeight,1),0,1)},H=()=>{const n=i.clientWidth,d=i.clientHeight;n===0||d===0||(h.aspect=n/d,h.updateProjectionMatrix(),o.setSize(n,d),a.material.uniforms.uAspect.value=n/d,ee())},te=document.querySelector(".wa-hero")??i,O=new IntersectionObserver(([n])=>{G=n.isIntersecting},{threshold:0});O.observe(te);const U=()=>{E=!document.hidden,E&&(_.start(),I())},X=n=>{const d=n.detail?.theme==="light";e=d?k.light:k.dark,o.setClearColor(e.background,1),s.fog.color.setHex(e.fog),m.intensity=d?1.1:.55,v.intensity=d?2:2.6,y.color.setHex(e.indigo),T.color.setHex(e.teal),T.emissive.setHex(e.teal),T.emissiveIntensity=d?.08:.28,P.color.setHex(e.tealLight),C.color.setHex(e.indigoLight),a.material.uniforms.uColorA.value.setHex(e.indigo),a.material.uniforms.uColorB.value.setHex(e.teal),a.material.uniforms.uColorBg.value.setHex(e.background),w.material.uniforms.uColorA.value.setHex(e.indigoLight),w.material.uniforms.uColorB.value.setHex(e.tealLight),g.traverse(p=>{p.material&&p.material.isLineBasicMaterial&&p.material.color.setHex(e.teal)}),g.userData.nodes?.children.forEach(p=>p.material.color.setHex(e.tealLight))};window.addEventListener("pointermove",V,{passive:!0}),window.addEventListener("scroll",F,{passive:!0}),window.addEventListener("resize",H,{passive:!0}),document.addEventListener("visibilitychange",U),document.addEventListener("wa:theme",X),H(),F();function I(){if(!E||(W=requestAnimationFrame(I),!G))return;const n=_.getElapsedTime();f.x=A(f.x,f.tx,.045),f.y=A(f.y,f.ty,.045);const d=(f.x-.5)*2,p=(f.y-.5)*2;c.rotation.y=A(c.rotation.y,d*.34,.06),c.rotation.x=A(c.rotation.x,p*.2,.06),c.position.x=(c.userData.baseX??0)+x*.9,c.position.y=x*1.9,c.position.z=x*-3.4,c.rotation.z=x*.32,L.rotation.z=Math.sin(n*.42)*.035,L.position.y=.05+Math.sin(n*.62)*.05,S.rotation.z=Math.sin(n*.54+1)*.06,S.position.y=.3+Math.sin(n*.72+.6)*.055,g.rotation.y=n*.16,g.rotation.x=Math.sin(n*.22)*.12,g.userData.nodes?.children.forEach((oe,ie)=>{const ne=1+Math.sin(n*2.1+ie*1.3)*.28;oe.scale.setScalar(ne)}),w.rotation.y=n*.022,w.material.uniforms.uTime.value=n,w.material.uniforms.uDisperse.value=A(w.material.uniforms.uDisperse.value,x,.06),a.material.uniforms.uTime.value=n,a.material.uniforms.uPointer.value.set(f.x,1-f.y),a.material.uniforms.uIntensity.value=1-x*.55,i.style.setProperty("--canvas-opacity",N(1-x*1.35,0,1).toFixed(3)),o.autoClear=!0,o.render(r,l),o.autoClear=!1,o.render(s,h)}return I(),i.classList.add("is-ready"),{destroy(){E=!1,cancelAnimationFrame(W),window.removeEventListener("pointermove",V),window.removeEventListener("scroll",F),window.removeEventListener("resize",H),document.removeEventListener("visibilitychange",U),document.removeEventListener("wa:theme",X),O.disconnect(),s.traverse(n=>{n.geometry&&n.geometry.dispose(),n.material&&(Array.isArray(n.material)?n.material:[n.material]).forEach(p=>p.dispose())}),a.geometry.dispose(),a.material.dispose(),o.dispose(),o.domElement.remove()}}}export{Ae as initHero};
