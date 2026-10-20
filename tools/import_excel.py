"""One-time build: python tools/import_excel.py google_drive (requires Pillow).
Reads every drawing anchor, including unlabeled columns. Never deduplicates product rows.
Browser assets use high-quality WebP; original non-raster files are preserved for download.
"""
import sys, json, zipfile, posixpath, hashlib, io, collections
from pathlib import Path
import xml.etree.ElementTree as ET
from PIL import Image, ImageOps
ROOT = Path(__file__).resolve().parents[1]
NS = {'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'x':'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing','a':'http://schemas.openxmlformats.org/drawingml/2006/main','r':'http://schemas.openxmlformats.org/officeDocument/2006/relationships'}
products=[]; media=[]; report=[]; unassigned=[]; stored={}
(ROOT/'assets/catalog').mkdir(parents=True,exist_ok=True)
def colname(i):
 s=''
 while i>=0:s=chr(65+i%26)+s;i=i//26-1
 return s
def asset(z,path):
 raw=z.read(path); digest=hashlib.sha256(raw).hexdigest()
 if digest in stored:return stored[digest]
 ext=Path(path).suffix.lower(); converted=False
 try:
  im=Image.open(io.BytesIO(raw)); im=ImageOps.exif_transpose(im)
  if im.mode not in ('RGB','RGBA'):im=im.convert('RGBA' if 'transparency' in im.info else 'RGB')
  # No resizing: preserve full pixel dimensions, optimize download/storage size.
  out=io.BytesIO(); im.save(out,'WEBP',quality=94,method=6); payload=out.getvalue(); ext='.webp';converted=True
 except (OSError,ValueError):payload=raw
 target='assets/catalog/'+digest[:24]+ext
 (ROOT/target).write_bytes(payload)
 stored[digest]={'path':target,'previewable':converted or ext in ('.png','.jpg','.jpeg','.gif','.webp'), 'original_name':Path(path).name,'sha256':digest}
 return stored[digest]
for file in sorted(Path(sys.argv[1] if len(sys.argv)>1 else ROOT/'google_drive').glob('*.xlsx')):
 z=zipfile.ZipFile(file); ss=[]
 if 'xl/sharedStrings.xml' in z.namelist():ss=[''.join(x.itertext()) for x in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',NS)]
 ws=ET.fromstring(z.read('xl/worksheets/sheet1.xml')); rows={}
 for row in ws.findall('s:sheetData/s:row',NS):
  rn=int(row.get('r')); cells={}
  if rn<3:continue
  for c in row.findall('s:c',NS):
   v=c.find('s:v',NS); inline=c.find('s:is',NS)
   value=v.text if v is not None else ''.join(inline.itertext()) if inline is not None else ''
   if c.get('t')=='s' and value:value=ss[int(value)]
   if value:cells[''.join(filter(str.isalpha,c.get('r')))]=value.strip()
  rows[rn]=cells
 anchors=[]
 for name in z.namelist():
  if not(name.startswith('xl/drawings/drawing') and name.endswith('.xml')):continue
  relpath=posixpath.join(posixpath.dirname(name),'_rels',posixpath.basename(name)+'.rels')
  rels={r.get('Id'):posixpath.normpath(posixpath.join(posixpath.dirname(name),r.get('Target'))) for r in ET.fromstring(z.read(relpath)) if r.get('TargetMode')!='External'}
  for anchor in ET.fromstring(z.read(name)):
   start=anchor.find('x:from',NS)
   for blip in anchor.findall('.//a:blip',NS):
    target=rels.get(blip.get('{'+NS['r']+'}embed'))
    if not target:continue
    if start is None:
     unassigned.append({'file':file.name,'reason':'anchor has no row','asset':asset(z,target)});continue
    rn=int(start.find('x:row',NS).text)+1; col=int(start.find('x:col',NS).text)
    anchors.append((rn,col,target,anchor.tag.split('}')[-1]))
 byrow=collections.defaultdict(list)
 for a in anchors:byrow[a[0]].append(a)
 before=len(products); beforem=len(media)
 for rn in sorted(set(rows)|set(byrow)):
  d=rows.get(rn,{})
  if not(d.get('C') or d.get('F') or byrow[rn]):continue
  if rn<3:
   for _,col,target,_ in byrow[rn]:unassigned.append({'file':file.name,'row':rn,'column':colname(col),'reason':'header image','asset':asset(z,target)})
   continue
  pid=len(products)+1
  products.append({'id':pid,'code':d.get('F',''),'brand':d.get('B','lufly'),'status':d.get('A','Running'),'description':d.get('C',''),'notes':'','source_file':file.name,'source_row':rn,'source_extra':json.dumps({k:v for k,v in d.items() if k not in 'ABCF'},ensure_ascii=False)})
  for _,col,target,anchor_type in byrow[rn]:
   a=asset(z,target)
   media.append({'id':len(media)+1,'product_id':pid,**a,'kind':'drawing' if col==4 else 'photo' if col==3 else 'extra','source_column':colname(col),'source_row':rn,'anchor_type':anchor_type,'sort_order':len(media)+1,'caption':''})
 report.append({'file':file.name,'products':len(products)-before,'attachments':len(media)-beforem,'anchors':len(anchors),'columns':dict(collections.Counter(colname(x[1]) for x in anchors)),'embedded_media_files':sum(n.startswith('xl/media/') for n in z.namelist())})
result={'version':1,'products':products,'media':media}
(ROOT/'data/catalog.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
audit={'files':report,'total_products':len(products),'total_attachments':len(media),'unique_assets':len(stored),'unassigned':unassigned,'mapping':'Excel drawing anchor top-left row; all columns retained. No product or attachment deduplication.','image_conversion':'Raster images converted to WebP quality 94, original pixel dimensions retained. Non-raster files preserved.'}
(ROOT/'data/import-report.json').write_text(json.dumps(audit,ensure_ascii=False,indent=2))
print(json.dumps(audit,ensure_ascii=False,indent=2))
assert not unassigned, 'Unassigned images must be reviewed'
assert sum(r['anchors'] for r in report)==len(media)
