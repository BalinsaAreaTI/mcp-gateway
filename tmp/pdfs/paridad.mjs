import fs from 'node:fs/promises';
const central=process.argv[2], out=process.argv[3];
const show=await fs.readFile(central+'/resources/js/Pages/Hojaservico/Show.vue','utf8');
const util=await import('data:text/javascript;base64,'+Buffer.from(await fs.readFile(central+'/resources/js/utils/reporteTecnico.js','utf8')).toString('base64'));
function extraer(name){const start=show.indexOf('\nconst '+name+' = ')+1;const end=show.indexOf('\n};',start);return show.slice(start+('const '+name+' = ').length,end+2);}
const fecha=Function('return ('+extraer('formatearFechaLarga')+')')();
const cases=[], expected=[];const vals=[null,'','0',0,'Texto con tildes áéíóú','Marca',false];
for(let n=0;n<1000;n++){
 const reporte={id:n+1,created_at:(2000+n%27)+'-10-29',cliente_nombre:'Cliente '+n};
 const item={id:n+11,orden_pdf:1+n%120,tipo_instrumento:['Balanza','Termómetro','Pesa Patrón','Manómetro'][n%4],marca:vals[n%7],modelo:vals[(n+1)%7],serie:'S-'+n,material:'Acero',codigo_int:'C-'+n,capacidad_rango:'100 kg',resolucion_division:'0.1',clase_tipo_metrologico:'M1',d_tecnico:vals[n%7],inf_tecnico:vals[(n+2)%7],d_fecha:n%2?'2026-10-29':null,inf_fecha:n%3?'2026-10-29':'2026-09-05',fallas_detectadas:vals[n%7],trabajos_realizados:vals[(n+3)%7]};
 let tables=[], labels=[];
 class PDF{constructor(){this.internal={pageSize:{width:210,height:297},getCurrentPageInfo:()=>({pageNumber:1})};this.lastAutoTable={finalY:10};}autoTable(p){tables.push(p.body);this.lastAutoTable.finalY=50;}text(t){labels.push(t);}setFontSize(){}setTextColor(){}setFont(){}addImage(){}setDrawColor(){}setLineWidth(){}line(){}addPage(){}output(){return new Blob(['test']);}save(){}}
 const swal={fire:async()=>({isConfirmed:false}),showLoading(){},hideLoading(){}};
 const generate=Function('jsPDF','Swal','props','numeroDeDocumento','numeroReporteTecnico','formatearFechaLarga','fotoReporteUrl','cargarImagenParaPdf','return ('+extraer('generarPDF')+')')(PDF,swal,{reporte},i=>'N° '+util.numeroReporteTecnico(reporte,i),util.numeroReporteTecnico,fecha,util.fotoReporteUrl,async()=>null);
 await generate(item);
 const equipo=tables.findIndex(t=>String(t?.[0]?.[0]).startsWith('DATOS DEL EQUIPO'));
 const inf=tables.findIndex(t=>t?.[0]?.[0]?.content==='INFORME TECNICO :');
 const res=tables.findIndex(t=>t?.[0]?.[0]?.content==='RESULTADO :');
 const numero=util.numeroReporteTecnico(reporte,item);
 expected.push({numero,archivo:'Reporte_'+numero+'.pdf',cliente:reporte.cliente_nombre,tecnicos:tables[1][0][1],fechas:tables[1][0][3],titulo_equipo:equipo<0?null:tables[equipo][0][0],filas_equipo:equipo<0?[]:[tables[equipo+1][0],tables[equipo+2][0]],ultima_fila:equipo<0?[]:tables[equipo+3][0],informe:tables[inf+1][0][0].content,resultado:tables[res+1][0][0].content});
 // autoTable convierte contenido null/undefined en texto vacío.
 for(const fila of expected.at(-1).filas_equipo)for(let j=0;j<fila.length;j++){if(fila[j]==null)fila[j]='';}
 cases.push({reporte,instrumento:item});
}
await fs.writeFile(out+'/casos.json',JSON.stringify(cases));await fs.writeFile(out+'/esperado.json',JSON.stringify(expected));console.log(cases.length+' casos capturados desde generarPDF de Show.vue');
